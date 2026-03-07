<template>
	<svg
		xmlns="http://www.w3.org/2000/svg"
		:viewBox="viewBox"
		:width="size"
		:height="size"
		fill="none"
		stroke="currentColor"
		:stroke-width="strokeWidth"
		stroke-linecap="round"
		stroke-linejoin="round"
		v-bind="$attrs">
		<template v-for="(shape, index) in shapes" :key="`${name}-${index}`">
			<path
				v-if="shape.type === 'path'"
				:d="shape.d"
				:fill="shape.fill ?? 'none'"
				:stroke="shape.stroke ?? 'currentColor'"
				:stroke-width="shape.strokeWidth ?? strokeWidth" />
			<circle
				v-else-if="shape.type === 'circle'"
				:cx="shape.cx"
				:cy="shape.cy"
				:r="shape.r"
				:fill="shape.fill ?? 'none'"
				:stroke="shape.stroke ?? 'currentColor'"
				:stroke-width="shape.strokeWidth ?? strokeWidth" />
			<rect
				v-else-if="shape.type === 'rect'"
				:x="shape.x"
				:y="shape.y"
				:width="shape.width"
				:height="shape.height"
				:rx="shape.rx ?? 0"
				:ry="shape.ry ?? shape.rx ?? 0"
				:fill="shape.fill ?? 'none'"
				:stroke="shape.stroke ?? 'currentColor'"
				:stroke-width="shape.strokeWidth ?? strokeWidth" />
			<line
				v-else-if="shape.type === 'line'"
				:x1="shape.x1"
				:y1="shape.y1"
				:x2="shape.x2"
				:y2="shape.y2"
				:stroke="shape.stroke ?? 'currentColor'"
				:stroke-width="shape.strokeWidth ?? strokeWidth" />
			<polyline
				v-else-if="shape.type === 'polyline'"
				:points="shape.points"
				:fill="shape.fill ?? 'none'"
				:stroke="shape.stroke ?? 'currentColor'"
				:stroke-width="shape.strokeWidth ?? strokeWidth" />
			<polygon
				v-else-if="shape.type === 'polygon'"
				:points="shape.points"
				:fill="shape.fill ?? 'none'"
				:stroke="shape.stroke ?? 'currentColor'"
				:stroke-width="shape.strokeWidth ?? strokeWidth" />
		</template>
	</svg>
</template>

<script>
/**
 * Local SVG icon renderer for ChoreStrike.
 *
 * Renders simple locally defined icon shapes without external runtime assets.
 */
export default {
	name: 'SvgIcon',
	inheritAttrs: false,
	props: {
		name: {
			type: String,
			required: true,
		},
		shapes: {
			type: Array,
			required: true,
		},
		size: {
			type: [Number, String],
			default: 20,
		},
		strokeWidth: {
			type: [Number, String],
			default: 1.75,
		},
		viewBox: {
			type: String,
			default: '0 0 24 24',
		},
	},
}
</script>
