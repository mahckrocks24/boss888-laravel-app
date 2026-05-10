module.exports = {
  testDir: './tests/mobile',
  testMatch: '**/*.spec.cjs',
  timeout: 30000,
  use: {
    headless: true,
    ignoreHTTPSErrors: true,
  },
  reporter: [['list'], ['html', { outputFolder: 'tests/mobile/report', open: 'never' }]],
};
