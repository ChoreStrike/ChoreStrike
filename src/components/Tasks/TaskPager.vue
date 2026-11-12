<!--
  @copyright Copyright (c) 2026 ChoreStrike Contributors
  @license MIT
-->

<template>
	<nav class="task-pager"
		data-testid="task-pager"
		:aria-label="t('chorestrike', 'Task pages')">
		<NcButton :disabled="page <= 1"
			data-testid="task-pager-previous"
			@click="$emit('go', page - 1)">
			{{ t('chorestrike', 'Previous') }}
		</NcButton>
		<span class="pager-status" data-testid="task-pager-status" aria-live="polite">
			{{ t('chorestrike', 'Page {page} of {pages}', { page, pages: pageCount }) }}
		</span>
		<NcButton :disabled="page >= pageCount"
			data-testid="task-pager-next"
			@click="$emit('go', page + 1)">
			{{ t('chorestrike', 'Next') }}
		</NcButton>
	</nav>
</template>

<script>
import { translate as t } from '@nextcloud/l10n'
import { NcButton } from '@nextcloud/vue'

/**
 * Pager of the task list by due date (CS-PLAN-084 D-084-02). Stateless;
 * the view owns the page and its URL mirror.
 */
export default {
	name: 'TaskPager',

	components: { NcButton },

	props: {
		page: {
			type: Number,
			required: true,
		},

		pageCount: {
			type: Number,
			required: true,
		},
	},

	emits: ['go'],

	methods: { t },
}
</script>

<style scoped>
.task-pager {
	display: flex;
	align-items: center;
	justify-content: center;
	gap: 1rem;
	margin-block-start: 1rem;
}

.pager-status {
	color: var(--color-main-text);
}
</style>
