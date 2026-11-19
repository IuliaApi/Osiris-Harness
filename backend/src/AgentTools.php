<?php

declare(strict_types=1);

/** Model tools operate only on the caller's authorized session in the current transaction. */
final class AgentTools
{
    private const SCAN_FILES = 64;
    private const MAX_MATCHES = 50;
    private const OUTPUT_BYTES = 3000;
    private const GLOB_PATTERN = '[A-Za-z0-9_.*?-]{1,64}';
    private const TOOLS = [
        'read' => ['Read UTF-8 text from a file in this session. Long results are marked as truncated.', ['path']],
        'glob' => ['List matching file basenames in this session. * matches any number of characters and ? matches one. Case-sensitive.', ['pattern']],
        'grep' => ['Search session files for a case-sensitive, literal single-line string. Return matching paths, line numbers and text.', ['pattern']],
        'write' => ['Write a new file or replace a file in this session. The chat permission setting determines whether approval is required.', ['path', 'content']],
        'edit' => ['Replace one exact, unique occurrence of oldText in a session file. The chat permission setting determines whether approval is required.', ['path', 'oldText', 'newText']],
    ];

    public static function definitions(): array
    {
        $text = ['type' => 'string', 'maxLength' => WorkspaceToolRuns::MAX_FILE_BYTES, 'description' => 'UTF-8 text, at most 8192 bytes.'];
        $fields = [
            'path' => ['type' => 'string', 'pattern' => '^' . WorkspaceToolRuns::BASENAME_PATTERN . '$', 'description' => 'One ASCII basename, without directories.'],
            'content' => $text, 'oldText' => $text + ['minLength' => 1], 'newText' => $text,
        ];
        $definitions = [];
        foreach (self::TOOLS as $name => [$description, $keys]) {
            $fields['pattern'] = $name === 'glob'
                ? ['type' => 'string', 'pattern' => '^' . self::GLOB_PATTERN . '$', 'description' => 'A flat basename glob, such as *.txt.']
                : ['type' => 'string', 'minLength' => 1, 'maxLength' => 256, 'description' => 'A nonempty literal without line breaks, at most 256 UTF-8 bytes.'];
            $definitions[] = ['name' => $name, 'description' => $description, 'parameters' => [
                'type' => 'object', 'properties' => array_intersect_key($fields, array_flip($keys)),
                'required' => $keys, 'additionalProperties' => false,
            ]];
        }
        return $definitions;
    }

    public static function execute(PDO $db, Auth $auth, string $sessionId, string $name, array $arguments): array
    {
        if (!isset(self::TOOLS[$name])) { return self::error('Unknown workspace tool.'); }
        $keys = self::TOOLS[$name][1];
        if (count($arguments) !== count($keys)) { return self::error('Expected arguments: ' . implode(', ', $keys) . '.'); }
        foreach ($keys as $key) {
            $value = $arguments[$key] ?? null;
            if (!is_string($value) || strlen($value) > WorkspaceToolRuns::MAX_FILE_BYTES || !isRepresentableText($value)) {
                return self::error("$key must be UTF-8 text of at most 8192 bytes, without unsupported control characters.");
            }
        }
        if ($name === 'glob' || $name === 'grep') {
            $pattern = $arguments['pattern'];
            if ($name === 'glob' && !preg_match('/\A' . self::GLOB_PATTERN . '\z/D', $pattern)) {
                return self::error('Expected a flat ASCII basename glob using letters, digits, _, ., -, * or ? (1–64 characters).');
            }
            if ($name === 'grep' && ($pattern === '' || strlen($pattern) > 256 || strpbrk($pattern, "\r\n") !== false)) {
                return self::error('Expected a nonempty literal on one line, at most 256 bytes.');
            }
            return self::search($db, $sessionId, $name, $pattern);
        }

        $path = $arguments['path'];
        if (!preg_match('/\A' . WorkspaceToolRuns::BASENAME_PATTERN . '\z/D', $path)) {
            return self::error('Expected one ASCII basename (1–64 characters), starting with a letter or digit.');
        }
        $base = executeSql($db, 'SELECT content FROM workspace_files WHERE session_id=? AND path=?', [$sessionId, $path])->fetchColumn();
        if ($name !== 'write' && $base === false) { return self::error('Workspace file not found.'); }
        if ($base !== false && !isRepresentableText($base)) { return self::error('Workspace file contains unsupported characters.'); }
        if ($name === 'read') { return ['output' => self::shorten($base, self::OUTPUT_BYTES, "\n[File output truncated]")]; }

        if ($name === 'edit') {
            $old = $arguments['oldText'];
            if ($old === '') { return self::error('oldText must not be empty.'); }
            $offset = strpos($base, $old);
            if ($offset === false) { return self::error('oldText was not found.'); }
            // Look one byte after the start to catch overlapping matches too.
            if (strpos($base, $old, $offset + 1) !== false) { return self::error('oldText is not unique. Include more context.'); }
            if (strlen($base) - strlen($old) + strlen($arguments['newText']) > WorkspaceToolRuns::MAX_FILE_BYTES) {
                return self::error('The edited file would exceed 8192 bytes.');
            }
            $content = substr_replace($base, $arguments['newText'], $offset, strlen($old));
        } else {
            $content = $arguments['content'];
        }
        $run = WorkspaceToolRuns::requestAgentWrite($db, $auth, $sessionId, $path, $content, $base === false ? 'missing' : hash('sha256', $base));
        return ['output' => $run['result'] ?? "Proposed $name of $path. Awaiting approval; no file was changed.",
            'runId' => $run['id'], 'needsReview' => $run['status'] === 'requested'];
    }

    private static function search(PDO $db, string $sessionId, string $name, string $pattern): array
    {
        $fields = $name === 'glob' ? 'path' : 'path, content';
        $rows = executeSql($db, 'SELECT ' . $fields . ' FROM workspace_files WHERE session_id=? ORDER BY path COLLATE "C" LIMIT ' . (self::SCAN_FILES + 1), [$sessionId]);
        $scanned = 0;
        $matches = 0;
        $output = '';
        while (($row = $rows->fetch(PDO::FETCH_ASSOC)) !== false) {
            if (++$scanned > self::SCAN_FILES) { return ['output' => $output . '[Search truncated: only the first 64 files were scanned]']; }
            $path = $row['path'];
            if ($name === 'glob') {
                if (!fnmatch($pattern, $path, FNM_NOESCAPE)) { continue; }
                $lines = [$path];
            } else {
                if (!isRepresentableText($row['content'])) { return self::error("Unsupported characters in $path."); }
                $lines = self::matchingLines($path, $row['content'], $pattern);
            }
            foreach ($lines as $line) {
                // Leave room for either truncation notice without cutting UTF-8.
                if (++$matches > self::MAX_MATCHES || strlen($output) + strlen($line) + 1 > self::OUTPUT_BYTES - 64) {
                    return ['output' => $output . '[Further matches omitted]'];
                }
                $output .= $line . "\n";
            }
        }
        return ['output' => $output === '' ? 'No matches.' : rtrim($output, "\n")];
    }

    private static function matchingLines(string $path, string $content, string $pattern): Generator
    {
        if (!str_contains($content, $pattern)) { return; }
        foreach (preg_split('/\r\n|[\r\n]/', $content) as $index => $line) {
            $offset = strpos($line, $pattern);
            if ($offset === false) { continue; }
            $preview = strlen($line) > 384 && $offset > 80 ? '…' . substr($line, $offset) : $line;
            yield $path . ':' . ($index + 1) . ': ' . self::shorten($preview, 384, '…');
        }
    }

    private static function shorten(string $text, int $bytes, string $suffix): string
    {
        if (strlen($text) <= $bytes) { return $text; }
        $end = $bytes - strlen($suffix);
        while ((ord($text[$end]) & 0xc0) === 0x80) { --$end; }
        return substr($text, 0, $end) . $suffix;
    }

    private static function error(string $message): array
    {
        return ['output' => 'Error: ' . $message];
    }
}
