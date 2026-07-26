<!--
  @copyright Copyright (c) 2026 ChoreStrike Contributors
  @license MIT
-->

<template>
	<div v-if="show" class="modal-overlay" @click.self="$emit('close')">
		<div
			class="modal-container"
			data-testid="modal"
			@click.stop>
			<button class="close-button"
				data-testid="modal-close"
				aria-label="Close"
				@click="$emit('close')">
				×
			</button>
			<slot name="header" />
			<slot />
		</div>
	</div>
</template>

<script>
export default {
	name: 'Modal',
	props: {
		show: {
			type: Boolean,
			default: true,
		},
	},
	emits: ['close'],
}
</script>

<style scoped>
.modal-overlay {
	position: fixed;
	inset: 0;
	background: rgba(0, 0, 0, 0.45);
	display: flex;
	align-items: center;
	justify-content: center;
	padding: 1rem;
	z-index: 1000;
}

.modal-container {
	position: relative;
	background: var(--color-main-background);
	border: 1px solid var(--color-border);
	border-radius: 10px;
	box-shadow: 0 12px 32px rgba(0, 0, 0, 0.2);
	max-width: min(640px, 96vw);
	width: 100%;
	max-height: 90vh;
	overflow: auto;
}

.close-button {
	position: absolute;
	top: 0.5rem;
	right: 0.5rem;
	width: 2rem;
	height: 2rem;
	border: none;
	background: transparent;
	font-size: 1.5rem;
	line-height: 1;
	cursor: pointer;
	color: var(--color-text-maxcontrast);
}
</style>
