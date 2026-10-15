import { defineConfig, devices } from '@playwright/test';
import os from 'node:os';
import path from 'node:path';

/**
 * Browser tests run against a real Laravel application (Orchestra Testbench)
 * served by PHP's built-in server. Fixtures are regenerated on every run.
 * Set PHP_BINARY to choose the PHP executable.
 */
const php = process.env.PHP_BINARY || 'php';
const port = Number(process.env.IPX_PORT || 8899);
const logs = path.join(os.tmpdir(), `ipx-log-viewer-browser-${port}`);

export default defineConfig({
    testDir: 'tests/browser',
    timeout: 30000,
    fullyParallel: true,
    reporter: [['list']],
    use: {
        baseURL: `http://127.0.0.1:${port}`,
        trace: 'off',
    },
    webServer: {
        command: `"${php}" tests/browser/fixtures.php "${logs}" && IPX_LOG_DIR="${logs}" PHP_CLI_SERVER_WORKERS=4 "${php}" -S 127.0.0.1:${port} tests/browser/server.php`,
        url: `http://127.0.0.1:${port}/log-viewer/assets/log-viewer.css`,
        reuseExistingServer: false,
        timeout: 30000,
    },
    projects: [
        { name: 'chromium', use: { ...devices['Desktop Chrome'] } },
        { name: 'firefox', use: { ...devices['Desktop Firefox'] } },
        { name: 'webkit', use: { ...devices['Desktop Safari'] } },
    ],
});
