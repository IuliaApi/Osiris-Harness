export function validChatModel(provider, model) {
  return typeof provider === 'string' && provider !== 'omp' && /^[a-z0-9][a-z0-9.-]{0,79}$/.test(provider)
    && typeof model === 'string' && model.length <= 240
    && /^~?[A-Za-z0-9][A-Za-z0-9_.:@+()[\]~-]*(?:\/[A-Za-z0-9_.:@+()[\]~-]+)*$/.test(model)
    && !/\/(?:\.|\.\.)(?:\/|$)/.test(model);
}
