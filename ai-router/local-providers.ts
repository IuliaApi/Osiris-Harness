// A login or a model never supplies a hostname, port, path or query. Only this explicit
// operator opt-in enables a local gateway; SDK *_BASE_URL and host credentials do not.
const portSetting: Record<string, string> = {
  'lm-studio': 'OSIRIS_LM_STUDIO_PORT', 'llama.cpp': 'OSIRIS_LLAMA_CPP_PORT',
  vllm: 'OSIRIS_VLLM_PORT', litellm: 'OSIRIS_LITELLM_PORT', bifrost: 'OSIRIS_BIFROST_PORT',
};
export function localProviderUrl(provider: string): string | undefined {
  if (!Object.hasOwn(portSetting, provider)) return undefined;
  const raw = process.env[portSetting[provider]];
  if (!raw || !/^[1-9][0-9]{3,4}$/.test(raw) || Number(raw) < 1024 || Number(raw) > 65535) return undefined;
  return `http://host.docker.internal:${raw}/v1`;
}
export function isLocalGateway(provider: string) { return Object.hasOwn(portSetting, provider); }
