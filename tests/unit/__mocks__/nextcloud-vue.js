export * from '@nextcloud/vue'

// Mock specific components if needed
export const NcButton = { name: 'NcButton', template: '<button><slot /></button>' }
export const NcIcon = { name: 'NcIcon', template: '<span><slot /></span>' }
export const NcLoadingIcon = { name: 'NcLoadingIcon', template: '<div class="loading"></div>' }
export const NcAvatar = { name: 'NcAvatar', template: '<div class="avatar"></div>' }
export const NcAppNavigation = { name: 'NcAppNavigation', template: '<nav><slot /></nav>' }
export const NcAppNavigationItem = { name: 'NcAppNavigationItem', template: '<div><slot /></div>' }
export const NcContent = { name: 'NcContent', template: '<main><slot /></main>' }
export const NcEmptyContent = { name: 'NcEmptyContent', template: '<div class="empty"><slot /></div>' }
export const NcModal = { name: 'NcModal', template: '<div class="modal"><slot /></div>' }
export const NcProgressBar = { name: 'NcProgressBar', template: '<div class="progress"><slot /></div>' }
export const NcTextField = { name: 'NcTextField', template: '<input />' }
export const NcTextarea = { name: 'NcTextarea', template: '<textarea></textarea>' }
export const NcCheckbox = { name: 'NcCheckbox', template: '<input type="checkbox" />' }
export const NcRadio = { name: 'NcRadio', template: '<input type="radio" />' }
export const NcSelect = { name: 'NcSelect', template: '<select><slot /></select>' }
export const NcActions = { name: 'NcActions', template: '<div class="actions"><slot /></div>' }
export const NcActionButton = { name: 'NcActionButton', template: '<button><slot /></button>' }
export const NcActionLink = { name: 'NcActionLink', template: '<a><slot /></a>' }
export const NcActionSeparator = { name: 'NcActionSeparator', template: '<hr />' }
export const NcActionInput = { name: 'NcActionInput', template: '<input />' }
export const NcActionText = { name: 'NcActionText', template: '<span><slot /></span>' }
