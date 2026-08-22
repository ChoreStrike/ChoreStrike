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

// Named toast API used by stores/components (`import { showError } from '@nextcloud/dialogs'`)
export const showError = jest.fn(() => Promise.resolve())
export const showSuccess = jest.fn(() => Promise.resolve())
export const showWarning = jest.fn(() => Promise.resolve())
export const showInfo = jest.fn(() => Promise.resolve())
