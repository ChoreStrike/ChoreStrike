export default {
  getCurrentUser: jest.fn(() => ({ uid: 'test-user', displayName: 'Test User' })),
  getRequestHeader: jest.fn(() => ({})),
  isLoggedIn: jest.fn(() => true),
  login: jest.fn(() => Promise.resolve()),
  logout: jest.fn(() => Promise.resolve()),
}
