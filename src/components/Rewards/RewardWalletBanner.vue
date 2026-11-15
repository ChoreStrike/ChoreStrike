<!--
  @copyright Copyright (c) 2026 ChoreStrike Contributors
  @license MIT
-->

<template>
	<!-- Available vs reserved: the caller's OWN wallet from the
	     existing game-state read — never a second wallet
	     authority and never a local request-price sum. -->
	<section class="wallet-banner" data-testid="rewards-wallet">
		<span class="wallet-line">
			{{ t('chorestrike', 'Available:') }}
			<strong data-testid="wallet-available">{{ formatCoinMinor(wallet.availableMinor) }}</strong>
		</span>
		<span class="wallet-line">
			{{ t('chorestrike', 'Reserved:') }}
			<strong data-testid="wallet-reserved">{{ formatCoinMinor(wallet.reservedMinor) }}</strong>
		</span>
		<p class="wallet-hint">
			{{ t('chorestrike', 'Reserved Coins are set aside for your open requests. A request is only spent once a reward is actually delivered.') }}
		</p>
		<p v-if="wallet.fundingHold"
			class="hold-note"
			data-testid="wallet-hold"
			role="status">
			{{ t('chorestrike', 'Some balances are currently being clarified. New requests and deliveries wait until that is resolved — nothing is lost.') }}
		</p>
	</section>
</template>

<script>
import { translate as t } from '@nextcloud/l10n'
import { formatCoinMinor } from '../../utils/minorUnits.js'

/** The wallet banner of the reward catalog (CS-PLAN-070 WP8 §3.4). */
export default {
	name: 'RewardWalletBanner',

	props: {
		/** The caller's own wallet: `availableMinor`, `reservedMinor`, `fundingHold`. */
		wallet: {
			type: Object,
			required: true,
		},
	},

	methods: {
		t,
		formatCoinMinor,
	},
}
</script>

<style scoped>
.wallet-banner {
	display: flex;
	flex-wrap: wrap;
	align-items: baseline;
	gap: 1.25rem;
	padding: 1rem 1.25rem;
	margin-bottom: 1.5rem;
	border: 1px solid var(--color-border);
	border-radius: 10px;
	background: var(--color-background-dark);
}

.wallet-hint {
	flex-basis: 100%;
	margin: 0.25rem 0 0 0;
	font-size: 0.85rem;
	color: var(--color-text-maxcontrast);
}

.hold-note {
	flex-basis: 100%;
	margin: 0.5rem 0 0 0;
	font-size: 0.9rem;
	color: var(--color-main-text);
}
</style>
