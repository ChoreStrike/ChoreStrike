export default {
  loadState: jest.fn((app, key, defaultValue) => defaultValue),
  loadStateMultiple: jest.fn((app, keys) => keys.reduce((acc, key) => ({ ...acc, [key]: null }), {})),
  saveState: jest.fn(),
  deleteState: jest.fn(),
}
