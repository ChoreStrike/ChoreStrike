export default {
  $on: jest.fn(),
  $off: jest.fn(),
  $emit: jest.fn(),
  on: jest.fn(),
  off: jest.fn(),
  emit: jest.fn(),
  subscribe: jest.fn(() => ({ unsubscribe: jest.fn() })),
  unsubscribe: jest.fn(),
}
