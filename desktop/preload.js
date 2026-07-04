// Preload script — context bridge antara renderer dan main process
// Saat ini tidak ada API khusus yang perlu diekspos
const { contextBridge } = require('electron');

contextBridge.exposeInMainWorld('pgManager', {
    version: process.versions.electron,
});
