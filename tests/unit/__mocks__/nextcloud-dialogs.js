export default {
  show: jest.fn(() => Promise.resolve()),
  hide: jest.fn(),
  confirm: jest.fn(() => Promise.resolve(true)),
  prompt: jest.fn(() => Promise.resolve('')),
  alert: jest.fn(() => Promise.resolve()),
  error: jest.fn(() => Promise.resolve()),
  warning: jest.fn(() => Promise.resolve()),
  info: jest.fn(() => Promise.resolve()),
  success: jest.fn(() => Promise.resolve()),
}
