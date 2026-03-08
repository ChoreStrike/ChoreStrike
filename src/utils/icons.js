/**
 * @copyright Copyright (c) 2026 ChoreStrike Contributors
 * @license MIT
 */

import { h } from 'vue'
import SvgIcon from '../components/Common/SvgIcon.vue'
import add20Regular from '@fluentui/svg-icons/icons/add_20_regular.svg'
import arrowClockwise20Regular from '@fluentui/svg-icons/icons/arrow_clockwise_20_regular.svg'
import arrowLeft20Regular from '@fluentui/svg-icons/icons/arrow_left_20_regular.svg'
import bed20Regular from '@fluentui/svg-icons/icons/bed_20_regular.svg'
import building20Regular from '@fluentui/svg-icons/icons/building_20_regular.svg'
import calendar20Regular from '@fluentui/svg-icons/icons/calendar_20_regular.svg'
import checkmark20Regular from '@fluentui/svg-icons/icons/checkmark_20_regular.svg'
import checkmarkCircle20Regular from '@fluentui/svg-icons/icons/checkmark_circle_20_regular.svg'
import chevronDown20Regular from '@fluentui/svg-icons/icons/chevron_down_20_regular.svg'
import chevronLeft20Regular from '@fluentui/svg-icons/icons/chevron_left_20_regular.svg'
import chevronRight20Regular from '@fluentui/svg-icons/icons/chevron_right_20_regular.svg'
import chevronUp20Regular from '@fluentui/svg-icons/icons/chevron_up_20_regular.svg'
import clock20Regular from '@fluentui/svg-icons/icons/clock_20_regular.svg'
import crown20Regular from '@fluentui/svg-icons/icons/crown_20_regular.svg'
import delete20Regular from '@fluentui/svg-icons/icons/delete_20_regular.svg'
import dismiss20Regular from '@fluentui/svg-icons/icons/dismiss_20_regular.svg'
import edit20Regular from '@fluentui/svg-icons/icons/edit_20_regular.svg'
import flash20Regular from '@fluentui/svg-icons/icons/flash_20_regular.svg'
import grid20Regular from '@fluentui/svg-icons/icons/grid_20_regular.svg'
import home20Regular from '@fluentui/svg-icons/icons/home_20_regular.svg'
import list20Regular from '@fluentui/svg-icons/icons/list_20_regular.svg'
import medal20Regular from '@fluentui/svg-icons/icons/medal_20_regular.svg'
import people20Regular from '@fluentui/svg-icons/icons/people_20_regular.svg'
import person20Regular from '@fluentui/svg-icons/icons/person_20_regular.svg'
import personAdd20Regular from '@fluentui/svg-icons/icons/person_add_20_regular.svg'
import personSubtract20Regular from '@fluentui/svg-icons/icons/person_subtract_20_regular.svg'
import search20Regular from '@fluentui/svg-icons/icons/search_20_regular.svg'
import star20Regular from '@fluentui/svg-icons/icons/star_20_regular.svg'
import trophy20Regular from '@fluentui/svg-icons/icons/trophy_20_regular.svg'
import warning20Regular from '@fluentui/svg-icons/icons/warning_20_regular.svg'

/**
 * Local Fluent-inspired SVG icon registry for ChoreStrike.
 *
 * The registry keeps the historic icon names stable while rendering local-only
 * SVG shapes through a shared component.
 */
const createFluentSvgComponent = (name, svgMarkup) => ({
	name: `${name}FluentSvgIcon`,
	inheritAttrs: false,
	props: {
		size: {
			type: [Number, String],
			default: 20,
		},
		strokeWidth: {
			type: [Number, String],
			default: 1.75,
		},
	},
	computed: {
		computedSize() {
			return typeof this.size === 'number' ? this.size : parseInt(this.size, 10)
		},
		styledMarkup() {
			return String(svgMarkup)
				.replace('<svg', `<svg width="${this.computedSize}" height="${this.computedSize}" class="icon-svg-content"`)
		},
	},
	template: '<span class="icon-svg" v-html="styledMarkup" v-bind="$attrs"></span>',
})

const createIconComponent = (name, shapes, viewBox = '0 0 24 24') => ({
	name: `${name}Icon`,
	props: {
		size: {
			type: [Number, String],
			default: 20,
		},
		strokeWidth: {
			type: [Number, String],
			default: 1.75,
		},
	},
	render() {
		return h(SvgIcon, {
			name,
			shapes,
			viewBox,
			size: this.size,
			strokeWidth: this.strokeWidth,
			...this.$attrs,
		})
	},
})

const fluentSvgIcons = {
	Home: createFluentSvgComponent('Home', home20Regular),
	House: createFluentSvgComponent('House', home20Regular),
	Building: createFluentSvgComponent('Building', building20Regular),
	Plus: createFluentSvgComponent('Plus', add20Regular),
	Search: createFluentSvgComponent('Search', search20Regular),
	X: createFluentSvgComponent('X', dismiss20Regular),
	Close: createFluentSvgComponent('Close', dismiss20Regular),
	Check: createFluentSvgComponent('Check', checkmark20Regular),
	CheckCircle: createFluentSvgComponent('CheckCircle', checkmarkCircle20Regular),
	ArrowLeft: createFluentSvgComponent('ArrowLeft', arrowLeft20Regular),
	ChevronDown: createFluentSvgComponent('ChevronDown', chevronDown20Regular),
	ChevronUp: createFluentSvgComponent('ChevronUp', chevronUp20Regular),
	ChevronLeft: createFluentSvgComponent('ChevronLeft', chevronLeft20Regular),
	ChevronRight: createFluentSvgComponent('ChevronRight', chevronRight20Regular),
	User: createFluentSvgComponent('User', person20Regular),
	Users: createFluentSvgComponent('Users', people20Regular),
	Calendar: createFluentSvgComponent('Calendar', calendar20Regular),
	Clock: createFluentSvgComponent('Clock', clock20Regular),
	Trash: createFluentSvgComponent('Trash', delete20Regular),
	Trash2: createFluentSvgComponent('Trash2', delete20Regular),
	Edit: createFluentSvgComponent('Edit', edit20Regular),
	Edit2: createFluentSvgComponent('Edit2', edit20Regular),
	Grid: createFluentSvgComponent('Grid', grid20Regular),
	List: createFluentSvgComponent('List', list20Regular),
	Bed: createFluentSvgComponent('Bed', bed20Regular),
	Star: createFluentSvgComponent('Star', star20Regular),
	Trophy: createFluentSvgComponent('Trophy', trophy20Regular),
	RotateCcw: createFluentSvgComponent('RotateCcw', arrowClockwise20Regular),
	Zap: createFluentSvgComponent('Zap', flash20Regular),
	AlertTriangle: createFluentSvgComponent('AlertTriangle', warning20Regular),
	Warning: createFluentSvgComponent('Warning', warning20Regular),
	Medal: createFluentSvgComponent('Medal', medal20Regular),
	Award: createFluentSvgComponent('Award', medal20Regular),
	Crown: createFluentSvgComponent('Crown', crown20Regular),
	UserPlus: createFluentSvgComponent('UserPlus', personAdd20Regular),
	UserMinus: createFluentSvgComponent('UserMinus', personSubtract20Regular),
}

const SHARED_SHAPES = {
	home: [
		{ type: 'path', d: 'M3 10.5 12 3l9 7.5' },
		{ type: 'path', d: 'M5 9.5V21h14V9.5' },
		{ type: 'rect', x: 9, y: 13, width: 6, height: 8, rx: 1 },
	],
	building: [
		{ type: 'rect', x: 5, y: 3, width: 14, height: 18, rx: 1 },
		{ type: 'line', x1: 9, y1: 7, x2: 9, y2: 17 },
		{ type: 'line', x1: 15, y1: 7, x2: 15, y2: 17 },
		{ type: 'line', x1: 5, y1: 11, x2: 19, y2: 11 },
	],
	menu: [
		{ type: 'line', x1: 4, y1: 7, x2: 20, y2: 7 },
		{ type: 'line', x1: 4, y1: 12, x2: 20, y2: 12 },
		{ type: 'line', x1: 4, y1: 17, x2: 20, y2: 17 },
	],
	close: [
		{ type: 'line', x1: 6, y1: 6, x2: 18, y2: 18 },
		{ type: 'line', x1: 18, y1: 6, x2: 6, y2: 18 },
	],
	plus: [
		{ type: 'line', x1: 12, y1: 5, x2: 12, y2: 19 },
		{ type: 'line', x1: 5, y1: 12, x2: 19, y2: 12 },
	],
	rectangle: [
		{ type: 'rect', x: 5, y: 6, width: 14, height: 12, rx: 2 },
	],
	copy: [
		{ type: 'rect', x: 8, y: 8, width: 10, height: 11, rx: 1 },
		{ type: 'rect', x: 5, y: 5, width: 10, height: 11, rx: 1 },
	],
	bed: [
		{ type: 'rect', x: 4, y: 11, width: 16, height: 5, rx: 1 },
		{ type: 'rect', x: 6, y: 8, width: 5, height: 3, rx: 1 },
		{ type: 'line', x1: 4, y1: 16, x2: 4, y2: 19 },
		{ type: 'line', x1: 20, y1: 16, x2: 20, y2: 19 },
	],
	bath: [
		{ type: 'path', d: 'M5 12h14a0 0 0 0 1 0 0v2a5 5 0 0 1-5 5H10a5 5 0 0 1-5-5v-2a0 0 0 0 1 0 0z' },
		{ type: 'line', x1: 7, y1: 12, x2: 7, y2: 7 },
		{ type: 'path', d: 'M7 7a2 2 0 0 1 4 0' },
	],
	utensils: [
		{ type: 'line', x1: 8, y1: 4, x2: 8, y2: 20 },
		{ type: 'line', x1: 6, y1: 4, x2: 6, y2: 10 },
		{ type: 'line', x1: 10, y1: 4, x2: 10, y2: 10 },
		{ type: 'line', x1: 15, y1: 4, x2: 18, y2: 20 },
		{ type: 'line', x1: 17, y1: 4, x2: 14, y2: 10 },
	],
	sofa: [
		{ type: 'rect', x: 5, y: 10, width: 14, height: 6, rx: 2 },
		{ type: 'rect', x: 7, y: 7, width: 10, height: 4, rx: 2 },
		{ type: 'line', x1: 7, y1: 16, x2: 7, y2: 19 },
		{ type: 'line', x1: 17, y1: 16, x2: 17, y2: 19 },
	],
	briefcase: [
		{ type: 'rect', x: 4, y: 8, width: 16, height: 10, rx: 2 },
		{ type: 'path', d: 'M9 8V6h6v2' },
		{ type: 'line', x1: 4, y1: 12, x2: 20, y2: 12 },
	],
	shirt: [
		{ type: 'path', d: 'M9 5 12 7 15 5l4 3-2 3-2-1v9H9v-9l-2 1-2-3z' },
	],
	dumbbell: [
		{ type: 'line', x1: 8, y1: 12, x2: 16, y2: 12 },
		{ type: 'line', x1: 6, y1: 9, x2: 6, y2: 15 },
		{ type: 'line', x1: 8, y1: 9, x2: 8, y2: 15 },
		{ type: 'line', x1: 16, y1: 9, x2: 16, y2: 15 },
		{ type: 'line', x1: 18, y1: 9, x2: 18, y2: 15 },
	],
	bookOpen: [
		{ type: 'path', d: 'M4 6.5C6 5 8 5 11 6v13c-3-1-5-1-7 .5z' },
		{ type: 'path', d: 'M20 6.5c-2-1.5-4-1.5-7-.5v13c3-1 5-1 7 .5z' },
	],
	gamepad: [
		{ type: 'path', d: 'M8 9h8a4 4 0 0 1 3.6 5.7l-1 2a2 2 0 0 1-2.8.9L13.5 16h-3l-2.3 1.6a2 2 0 0 1-2.8-.9l-1-2A4 4 0 0 1 8 9z' },
		{ type: 'line', x1: 9, y1: 13, x2: 13, y2: 13 },
		{ type: 'line', x1: 11, y1: 11, x2: 11, y2: 15 },
		{ type: 'circle', cx: 16, cy: 12, r: 0.8 },
		{ type: 'circle', cx: 18, cy: 14, r: 0.8 },
	],
	tv: [
		{ type: 'rect', x: 4, y: 6, width: 16, height: 11, rx: 2 },
		{ type: 'line', x1: 10, y1: 20, x2: 14, y2: 20 },
		{ type: 'line', x1: 12, y1: 17, x2: 12, y2: 20 },
	],
	tree: [
		{ type: 'polygon', points: '12 4 18 12 14.5 12 18.5 17 5.5 17 9.5 12 6 12' },
		{ type: 'line', x1: 12, y1: 17, x2: 12, y2: 21 },
	],
	package: [
		{ type: 'polygon', points: '12 3 19 7 12 11 5 7' },
		{ type: 'path', d: 'M5 7v10l7 4 7-4V7' },
		{ type: 'line', x1: 12, y1: 11, x2: 12, y2: 21 },
	],
	minus: [
		{ type: 'line', x1: 5, y1: 12, x2: 19, y2: 12 },
	],
	check: [
		{ type: 'polyline', points: '5 13 10 18 19 7' },
	],
	circle: [
		{ type: 'circle', cx: 12, cy: 12, r: 8 },
	],
	checkCircle: [
		{ type: 'circle', cx: 12, cy: 12, r: 9 },
		{ type: 'polyline', points: '8 12 11 15 16 9' },
	],
	trash: [
		{ type: 'polyline', points: '4 7 20 7' },
		{ type: 'path', d: 'M9 7V5h6v2' },
		{ type: 'rect', x: 7, y: 7, width: 10, height: 12, rx: 1 },
		{ type: 'line', x1: 10, y1: 10, x2: 10, y2: 16 },
		{ type: 'line', x1: 14, y1: 10, x2: 14, y2: 16 },
	],
	edit: [
		{ type: 'path', d: 'M4 20l4-.8L18 9.2 14.8 6 4.8 16z' },
		{ type: 'line', x1: 13.5, y1: 7.3, x2: 16.7, y2: 10.5 },
	],
	arrowLeft: [
		{ type: 'line', x1: 19, y1: 12, x2: 5, y2: 12 },
		{ type: 'polyline', points: '11 6 5 12 11 18' },
	],
	arrowRight: [
		{ type: 'line', x1: 5, y1: 12, x2: 19, y2: 12 },
		{ type: 'polyline', points: '13 6 19 12 13 18' },
	],
	arrowUp: [
		{ type: 'line', x1: 12, y1: 19, x2: 12, y2: 5 },
		{ type: 'polyline', points: '6 11 12 5 18 11' },
	],
	arrowDown: [
		{ type: 'line', x1: 12, y1: 5, x2: 12, y2: 19 },
		{ type: 'polyline', points: '6 13 12 19 18 13' },
	],
	chevronDown: [{ type: 'polyline', points: '6 9 12 15 18 9' }],
	chevronUp: [{ type: 'polyline', points: '6 15 12 9 18 15' }],
	chevronLeft: [{ type: 'polyline', points: '15 6 9 12 15 18' }],
	chevronRight: [{ type: 'polyline', points: '9 6 15 12 9 18' }],
	search: [
		{ type: 'circle', cx: 11, cy: 11, r: 6 },
		{ type: 'line', x1: 16, y1: 16, x2: 20, y2: 20 },
	],
	clock: [
		{ type: 'circle', cx: 12, cy: 12, r: 9 },
		{ type: 'line', x1: 12, y1: 7, x2: 12, y2: 12 },
		{ type: 'line', x1: 12, y1: 12, x2: 16, y2: 14 },
	],
	calendar: [
		{ type: 'rect', x: 4, y: 6, width: 16, height: 14, rx: 2 },
		{ type: 'line', x1: 8, y1: 3, x2: 8, y2: 8 },
		{ type: 'line', x1: 16, y1: 3, x2: 16, y2: 8 },
		{ type: 'line', x1: 4, y1: 10, x2: 20, y2: 10 },
	],
	user: [
		{ type: 'circle', cx: 12, cy: 8, r: 3.5 },
		{ type: 'path', d: 'M5 20c1.5-3.5 4-5 7-5s5.5 1.5 7 5' },
	],
	users: [
		{ type: 'circle', cx: 9, cy: 9, r: 3 },
		{ type: 'circle', cx: 16, cy: 10, r: 2.5 },
		{ type: 'path', d: 'M3.5 19c1.2-2.8 3.2-4 5.5-4 2.4 0 4.3 1.2 5.5 4' },
		{ type: 'path', d: 'M13 19c.8-1.9 2.2-2.9 4-2.9 1.3 0 2.4.5 3.5 1.9' },
	],
	zap: [
		{ type: 'polygon', points: '13 2 5 13 11 13 10 22 19 10 13 10' },
	],
	star: [{ type: 'polygon', points: '12 3 14.9 9 21 9.8 16.5 14.1 17.8 20.5 12 17.2 6.2 20.5 7.5 14.1 3 9.8 9.1 9' }],
	medal: [
		{ type: 'polygon', points: '9 3 12 8 15 3 18 3 14 10 10 10 6 3' },
		{ type: 'circle', cx: 12, cy: 15, r: 5 },
	],
	trophy: [
		{ type: 'path', d: 'M8 4h8v4a4 4 0 0 1-8 0z' },
		{ type: 'path', d: 'M8 5H5a3 3 0 0 0 3 4' },
		{ type: 'path', d: 'M16 5h3a3 3 0 0 1-3 4' },
		{ type: 'line', x1: 12, y1: 12, x2: 12, y2: 17 },
		{ type: 'line', x1: 9, y1: 21, x2: 15, y2: 21 },
	],
	crown: [
		{ type: 'polygon', points: '4 18 6.5 7 12 13 17.5 7 20 18' },
		{ type: 'line', x1: 4, y1: 18, x2: 20, y2: 18 },
	],
	alertTriangle: [
		{ type: 'polygon', points: '12 3 21 20 3 20' },
		{ type: 'line', x1: 12, y1: 9, x2: 12, y2: 14 },
		{ type: 'line', x1: 12, y1: 17, x2: 12, y2: 17.5 },
	],
	alertCircle: [
		{ type: 'circle', cx: 12, cy: 12, r: 9 },
		{ type: 'line', x1: 12, y1: 8, x2: 12, y2: 13 },
		{ type: 'line', x1: 12, y1: 16, x2: 12, y2: 16.5 },
	],
	info: [
		{ type: 'circle', cx: 12, cy: 12, r: 9 },
		{ type: 'line', x1: 12, y1: 10.5, x2: 12, y2: 16 },
		{ type: 'line', x1: 12, y1: 7.5, x2: 12, y2: 7.6 },
	],
	list: [
		{ type: 'line', x1: 9, y1: 7, x2: 19, y2: 7 },
		{ type: 'line', x1: 9, y1: 12, x2: 19, y2: 12 },
		{ type: 'line', x1: 9, y1: 17, x2: 19, y2: 17 },
		{ type: 'circle', cx: 5.5, cy: 7, r: 1 },
		{ type: 'circle', cx: 5.5, cy: 12, r: 1 },
		{ type: 'circle', cx: 5.5, cy: 17, r: 1 },
	],
	grid: [
		{ type: 'rect', x: 4, y: 4, width: 6, height: 6, rx: 1 },
		{ type: 'rect', x: 14, y: 4, width: 6, height: 6, rx: 1 },
		{ type: 'rect', x: 4, y: 14, width: 6, height: 6, rx: 1 },
		{ type: 'rect', x: 14, y: 14, width: 6, height: 6, rx: 1 },
	],
	moreHorizontal: [
		{ type: 'circle', cx: 6, cy: 12, r: 1.2 },
		{ type: 'circle', cx: 12, cy: 12, r: 1.2 },
		{ type: 'circle', cx: 18, cy: 12, r: 1.2 },
	],
	moreVertical: [
		{ type: 'circle', cx: 12, cy: 6, r: 1.2 },
		{ type: 'circle', cx: 12, cy: 12, r: 1.2 },
		{ type: 'circle', cx: 12, cy: 18, r: 1.2 },
	],
	refresh: [
		{ type: 'path', d: 'M20 11a8 8 0 1 0 1 4' },
		{ type: 'polyline', points: '20 5 20 11 14 11' },
	],
	link: [
		{ type: 'path', d: 'M10 14l-2 2a3 3 0 1 1-4-4l3-3a3 3 0 0 1 4 0' },
		{ type: 'path', d: 'M14 10l2-2a3 3 0 1 1 4 4l-3 3a3 3 0 0 1-4 0' },
		{ type: 'line', x1: 9, y1: 15, x2: 15, y2: 9 },
	],
	location: [
		{ type: 'path', d: 'M12 21s6-5.5 6-11a6 6 0 1 0-12 0c0 5.5 6 11 6 11z' },
		{ type: 'circle', cx: 12, cy: 10, r: 2.5 },
	],
	bell: [
		{ type: 'path', d: 'M7 17h10l-1.2-1.5V11a3.8 3.8 0 1 0-7.6 0v4.5z' },
		{ type: 'line', x1: 10, y1: 19, x2: 14, y2: 19 },
	],
	settings: [
		{ type: 'circle', cx: 12, cy: 12, r: 3 },
		{ type: 'path', d: 'M12 3v2.2M12 18.8V21M3 12h2.2M18.8 12H21M5.6 5.6l1.6 1.6M16.8 16.8l1.6 1.6M18.4 5.6l-1.6 1.6M7.2 16.8l-1.6 1.6' },
	],
	shield: [
		{ type: 'path', d: 'M12 3l7 3v5c0 5-3 8-7 10-4-2-7-5-7-10V6z' },
	],
	heart: [{ type: 'path', d: 'M12 20s-7-4.4-7-10a4 4 0 0 1 7-2.3A4 4 0 0 1 19 10c0 5.6-7 10-7 10z' }],
	thumbsUp: [{ type: 'path', d: 'M10 21H6V10h4zm2-11 2-6 3 1v4h3l-1 10h-7z' }],
	messageSquare: [
		{ type: 'path', d: 'M5 6h14v9H9l-4 4z' },
	],
	activity: [
		{ type: 'polyline', points: '3 13 8 13 10 8 14 17 16 13 21 13' },
	],
	barChart: [
		{ type: 'line', x1: 5, y1: 20, x2: 5, y2: 10 },
		{ type: 'line', x1: 12, y1: 20, x2: 12, y2: 6 },
		{ type: 'line', x1: 19, y1: 20, x2: 19, y2: 13 },
	],
	pieChart: [
		{ type: 'path', d: 'M12 3a9 9 0 1 1-9 9h9z' },
		{ type: 'path', d: 'M13 3a9 9 0 0 1 8 8h-8z' },
	],
	gripVertical: [
		{ type: 'circle', cx: 9, cy: 7, r: 1 },
		{ type: 'circle', cx: 15, cy: 7, r: 1 },
		{ type: 'circle', cx: 9, cy: 12, r: 1 },
		{ type: 'circle', cx: 15, cy: 12, r: 1 },
		{ type: 'circle', cx: 9, cy: 17, r: 1 },
		{ type: 'circle', cx: 15, cy: 17, r: 1 },
	],
}

const iconDefinitions = {
	Home: SHARED_SHAPES.home,
	House: SHARED_SHAPES.home,
	Building: SHARED_SHAPES.building,
	Menu: SHARED_SHAPES.menu,
	X: SHARED_SHAPES.close,
	Close: SHARED_SHAPES.close,
	ArrowLeft: SHARED_SHAPES.arrowLeft,
	ArrowRight: SHARED_SHAPES.arrowRight,
	ArrowUp: SHARED_SHAPES.arrowUp,
	ArrowDown: SHARED_SHAPES.arrowDown,
	ChevronDown: SHARED_SHAPES.chevronDown,
	ChevronUp: SHARED_SHAPES.chevronUp,
	ChevronLeft: SHARED_SHAPES.chevronLeft,
	ChevronRight: SHARED_SHAPES.chevronRight,
	MoreHorizontal: SHARED_SHAPES.moreHorizontal,
	MoreVertical: SHARED_SHAPES.moreVertical,
	Search: SHARED_SHAPES.search,
	Settings: SHARED_SHAPES.settings,
	Plus: SHARED_SHAPES.plus,
	Minus: SHARED_SHAPES.minus,
	Edit: SHARED_SHAPES.edit,
	Edit2: SHARED_SHAPES.edit,
	Trash: SHARED_SHAPES.trash,
	Trash2: SHARED_SHAPES.trash,
	CheckCircle: SHARED_SHAPES.checkCircle,
	Check: SHARED_SHAPES.check,
	Circle: SHARED_SHAPES.circle,
	Save: SHARED_SHAPES.check,
	Copy: SHARED_SHAPES.copy,
	RefreshCw: SHARED_SHAPES.refresh,
	Refresh: SHARED_SHAPES.refresh,
	RotateCcw: SHARED_SHAPES.refresh,
	Undo: SHARED_SHAPES.refresh,
	Redo: SHARED_SHAPES.refresh,
	Download: SHARED_SHAPES.arrowDown,
	Upload: SHARED_SHAPES.arrowUp,
	ExternalLink: SHARED_SHAPES.link,
	Link: SHARED_SHAPES.link,
	Share2: SHARED_SHAPES.link,
	Printer: SHARED_SHAPES.building,
	Bed: SHARED_SHAPES.bed,
	Bath: SHARED_SHAPES.bath,
	Utensils: SHARED_SHAPES.utensils,
	Sofa: SHARED_SHAPES.sofa,
	DoorOpen: SHARED_SHAPES.arrowRight,
	WashingMachine: SHARED_SHAPES.rectangle,
	Briefcase: SHARED_SHAPES.briefcase,
	BookOpen: SHARED_SHAPES.bookOpen,
	Gamepad2: SHARED_SHAPES.gamepad,
	Dumbbell: SHARED_SHAPES.dumbbell,
	Tv: SHARED_SHAPES.tv,
	Trees: SHARED_SHAPES.tree,
	Baby: SHARED_SHAPES.user,
	Armchair: SHARED_SHAPES.sofa,
	Car: SHARED_SHAPES.arrowRight,
	Package: SHARED_SHAPES.package,
	Eraser: SHARED_SHAPES.edit,
	Sparkles: SHARED_SHAPES.star,
	ShoppingCart: SHARED_SHAPES.package,
	Shirt: SHARED_SHAPES.shirt,
	Wrench: SHARED_SHAPES.settings,
	Star: SHARED_SHAPES.star,
	Zap: SHARED_SHAPES.zap,
	Flame: SHARED_SHAPES.alertTriangle,
	Trophy: SHARED_SHAPES.trophy,
	Award: SHARED_SHAPES.medal,
	Medal: SHARED_SHAPES.medal,
	Crown: SHARED_SHAPES.crown,
	Target: SHARED_SHAPES.circle,
	TrendingUp: SHARED_SHAPES.arrowUp,
	Users: SHARED_SHAPES.users,
	User: SHARED_SHAPES.user,
	UserPlus: SHARED_SHAPES.users,
	UserMinus: SHARED_SHAPES.users,
	Calendar: SHARED_SHAPES.calendar,
	Clock: SHARED_SHAPES.clock,
	Bell: SHARED_SHAPES.bell,
	AlertCircle: SHARED_SHAPES.alertCircle,
	AlertTriangle: SHARED_SHAPES.alertTriangle,
	Warning: SHARED_SHAPES.alertTriangle,
	Lightbulb: SHARED_SHAPES.star,
	Info: SHARED_SHAPES.info,
	HelpCircle: SHARED_SHAPES.alertCircle,
	Heart: SHARED_SHAPES.heart,
	ThumbsUp: SHARED_SHAPES.thumbsUp,
	MessageSquare: SHARED_SHAPES.messageSquare,
	Grid: SHARED_SHAPES.grid,
	List: SHARED_SHAPES.list,
	LayoutGrid: SHARED_SHAPES.grid,
	Filter: SHARED_SHAPES.chevronDown,
	SortAsc: SHARED_SHAPES.arrowUp,
	SortDesc: SHARED_SHAPES.arrowDown,
	BarChart3: SHARED_SHAPES.barChart,
	PieChart: SHARED_SHAPES.pieChart,
	Activity: SHARED_SHAPES.activity,
	Shield: SHARED_SHAPES.shield,
	Lock: SHARED_SHAPES.shield,
	Unlock: SHARED_SHAPES.shield,
	Eye: SHARED_SHAPES.circle,
	EyeOff: SHARED_SHAPES.circle,
	Key: SHARED_SHAPES.link,
	LogOut: SHARED_SHAPES.arrowRight,
	LogIn: SHARED_SHAPES.arrowLeft,
	MapPin: SHARED_SHAPES.location,
	Navigation: SHARED_SHAPES.location,
	Locate: SHARED_SHAPES.location,
	Globe: SHARED_SHAPES.circle,
	Battery: SHARED_SHAPES.rectangle,
	Task: SHARED_SHAPES.list,
	ClipboardList: SHARED_SHAPES.list,
	GripVertical: SHARED_SHAPES.gripVertical,
}

export const icons = Object.fromEntries(
	Object.entries(iconDefinitions).map(([name, shapes]) => [name, createIconComponent(name, shapes)])
)

Object.assign(icons, fluentSvgIcons)

/**
 * Get icon component by name
 *
 * @param {string} name - The icon name
 * @return {object|undefined} The icon component or undefined if not found
 */
export function getIcon(name) {
	return icons[name]
}

/**
 * Check if an icon exists
 *
 * @param {string} name - The icon name
 * @return {boolean} True if the icon exists
 */
export function hasIcon(name) {
	return name in icons
}

export default icons
