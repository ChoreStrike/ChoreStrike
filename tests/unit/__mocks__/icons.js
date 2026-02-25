export const getIcon = jest.fn((name) => ({
	name: 'MockIcon',
	props: ['size'],
	template: `<span class="icon-${name}">{{ name }}</span>`,
}))

export const hasIcon = jest.fn(() => true)

export const icons = {}

export default icons
