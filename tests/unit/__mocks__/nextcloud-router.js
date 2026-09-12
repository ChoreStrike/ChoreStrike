
export const generateUrl = jest.fn((path) => '/index.php' + path)

export default {
  currentRoute: {
    name: '',
    path: '',
    params: {},
    query: {},
    meta: {},
  },
  push: jest.fn(),
  replace: jest.fn(),
  go: jest.fn(),
  back: jest.fn(),
  forward: jest.fn(),
  beforeEach: jest.fn(),
  beforeResolve: jest.fn(),
  afterEach: jest.fn(),
  resolve: jest.fn(() => ({ href: '' })),
  addRoute: jest.fn(),
  removeRoute: jest.fn(),
  hasRoute: jest.fn(),
  getRoutes: jest.fn(() => []),
}
