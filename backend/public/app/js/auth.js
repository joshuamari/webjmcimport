(function () {
  const userLabel = document.getElementById('userLabel');
  const accessDenied = document.getElementById('accessDenied');
  const accessMessage = document.getElementById('accessMessage');
  const importPanel = document.getElementById('importPanel');
  const groupSelect = document.getElementById('groupSelect');

  const loginUrl = window.KDT_LOGIN_URL || '/';

  function showDenied(message) {
    accessMessage.textContent = message || 'No access in Web JMC Import';
    accessDenied.classList.remove('hidden');
    importPanel.classList.add('hidden');
  }

  function showImport(user) {
    accessDenied.classList.add('hidden');
    importPanel.classList.remove('hidden');
    userLabel.textContent = user.username + ' (' + user.employeeNumber + ')';

    groupSelect.innerHTML = '';
    (user.groups || []).forEach(function (group) {
      const option = document.createElement('option');
      option.value = group;
      option.textContent = group;
      groupSelect.appendChild(option);
    });
  }

  WebJmcApi.me().then(function (result) {
    if (result.status === 401) {
      userLabel.textContent = 'Not signed in';
      window.location.href = loginUrl;
      return;
    }

    if (result.status === 403 || (result.body && result.body.data && result.body.data.canImport === false)) {
      const message = (result.body && result.body.message) || 'No access in Web JMC Import';
      userLabel.textContent = 'Access denied';
      showDenied(message);
      return;
    }

    if (!result.ok || !result.body || !result.body.data) {
      userLabel.textContent = 'Session check failed';
      showDenied((result.body && result.body.message) || 'Unable to resolve user session.');
      return;
    }

    const user = result.body.data;
    if (!user.canImport) {
      showDenied('No access in Web JMC Import');
      return;
    }

    showImport(user);
  }).catch(function (err) {
    userLabel.textContent = 'Session check failed';
    showDenied(err.message || 'Unable to reach API.');
  });
})();
