const {spawnSync} = require('node:child_process');
for (const script of ['modals-browser', 'payment-browser', 'official-use-browser', 'reservation-datatable-browser']) {
    const result = spawnSync(process.execPath, ['tests/Frontend/' + script + '.cjs'], {stdio: 'inherit', windowsHide: true});
    if (result.error) throw result.error;
    if (result.status !== 0) process.exit(result.status || 1);
}
