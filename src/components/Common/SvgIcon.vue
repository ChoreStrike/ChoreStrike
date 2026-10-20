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
		<template v-for="(shape, index) in shapes">
			<path
				v-if="shape.type === 'path'"
				:key="`${name}-${index}`"
				:d="shape.d"
				:fill="shape.fill ?? 'none'"
				:stroke="shape.stroke ?? 'currentColor'"
				:stroke-width="shape.strokeWidth ?? strokeWidth" />
			<circle
				v-else-if="shape.type === 'circle'"
				:key="`${name}-${index}`"
				:cx="shape.cx"
				:cy="shape.cy"
				:r="shape.r"
				:fill="shape.fill ?? 'none'"
				:stroke="shape.stroke ?? 'currentColor'"
				:stroke-width="shape.strokeWidth ?? strokeWidth" />
			<rect
				v-else-if="shape.type === 'rect'"
				:key="`${name}-${index}`"
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
				:key="`${name}-${index}`"
				:x1="shape.x1"
				:y1="shape.y1"
				:x2="shape.x2"
				:y2="shape.y2"
				:stroke="shape.stroke ?? 'currentColor'"
				:stroke-width="shape.strokeWidth ?? strokeWidth" />
			<polyline
				v-else-if="shape.type === 'polyline'"
				:key="`${name}-${index}`"
				:points="shape.points"
				:fill="shape.fill ?? 'none'"
				:stroke="shape.stroke ?? 'currentColor'"
				:stroke-width="shape.strokeWidth ?? strokeWidth" />
			<polygon
				v-else-if="shape.type === 'polygon'"
				:key="`${name}-${index}`"
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
