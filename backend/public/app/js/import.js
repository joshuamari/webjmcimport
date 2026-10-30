(function () {
  const importBtn = document.getElementById('importBtn');
  const groupSelect = document.getElementById('groupSelect');
  const excelFile = document.getElementById('excelFile');
  const logBox = document.getElementById('logBox');

  function writeLog(lines) {
    if (Array.isArray(lines)) {
      logBox.textContent = lines.join('\n');
    } else {
      logBox.textContent = String(lines || '');
    }
  }

  importBtn.addEventListener('click', function () {
    const group = groupSelect.value;
    const file = excelFile.files && excelFile.files[0];

    if (!group) {
      writeLog('Select a group first.');
      return;
    }
    if (!file) {
      writeLog('Select an Excel .xlsx file first.');
      return;
    }

    importBtn.disabled = true;
    writeLog('IMPORTING JMC EXCEL...');

    WebJmcApi.importExcel(group, file).then(function (result) {
      importBtn.disabled = false;
      const body = result.body || {};
      const data = body.data || {};
      const logs = data.logs || [body.message || ('HTTP ' + result.status)];
      writeLog(logs);

      if (result.ok && data.completedProjects && data.completedProjects.length) {
        const jmcUrl = window.WEBJMR_JMC_URL;
        if (jmcUrl) {
          window.open(jmcUrl, '_blank');
        }
      }
    }).catch(function (err) {
      importBtn.disabled = false;
      writeLog(err.message || 'Import failed.');
    });
  });
})();
