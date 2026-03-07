export const getCurrentUser = jest.fn(() => ({ uid: 'test-user', displayName: 'Test User' }))
export const getRequestHeader = jest.fn(() => ({}))
export const isLoggedIn = jest.fn(() => true)
export const login = jest.fn(() => Promise.resolve())
export const logout = jest.fn(() => Promise.resolve())

export default {
	getCurrentUser,
	getRequestHeader,
	isLoggedIn,
	login,
	logout,
}
