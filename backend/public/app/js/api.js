(function (global) {
  const API_BASE = global.WEB_JMC_API_BASE || 'http://127.0.0.1:8000/api';

  async function request(path, options) {
    const response = await fetch(API_BASE + path, Object.assign({
      credentials: 'include',
      headers: {
        Accept: 'application/json',
      },
    }, options || {}));

    let body = null;
    const text = await response.text();
    if (text) {
      try {
        body = JSON.parse(text);
      } catch (e) {
        body = { message: text };
      }
    }

    return { ok: response.ok, status: response.status, body: body };
  }

  global.WebJmcApi = {
    me: function () {
      return request('/me');
    },
    health: function () {
      return request('/health');
    },
    importExcel: function (group, file) {
      const form = new FormData();
      form.append('group', group);
      form.append('file', file);
      return request('/jmc/import', {
        method: 'POST',
        body: form,
      });
    },
  };
})(window);
