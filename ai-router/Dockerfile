FROM oven/bun:1.4.4@sha256:1d22548f177f4ff38e0c15baa3c2c46c6c2060ba2d1bc573bb4720b407548f1d AS dependencies
WORKDIR /app
COPY ai-router/package.json ai-router/bun.lock ./
RUN bun install --frozen-lockfile --production

FROM oven/bun:1.4.4@sha256:1d22548f177f4ff38e0c15baa3c2c46c6c2060ba2d1bc573bb4720b407548f1d
WORKDIR /app
COPY --from=dependencies /app/node_modules ./node_modules
COPY --from=dependencies /app/bun.lock ./bun.lock
COPY ai-router/ ./
ENV HOME=/tmp
USER bun
EXPOSE 8090
CMD ["bun", "run", "server.ts"]
