/**
 * Mobile utilities for touch interactions and responsive behavior
 */

import { ref, onMounted, onUnmounted } from 'vue'

/**
 * Check if device is touch-enabled
 * @return {boolean} True if touch device
 */
export function isTouchDevice() {
	// eslint-disable-next-line n/no-unsupported-features/node-builtins
	return 'ontouchstart' in window || (typeof navigator !== 'undefined' && 'maxTouchPoints' in navigator && navigator.maxTouchPoints > 0)
}

/**
 * Check if device is mobile (small screen)
 * @return {boolean} True if mobile viewport
 */
export function isMobileViewport() {
	return window.innerWidth < 768
}

/**
 * Check if device is tablet
 * @return {boolean} True if tablet viewport
 */
export function isTabletViewport() {
	return window.innerWidth >= 768 && window.innerWidth < 1024
}

/**
 * Vue composable for viewport detection
 * @return {object} Viewport reactive refs
 */
export function useViewport() {
	const isMobile = ref(isMobileViewport())
	const isTablet = ref(isTabletViewport())
	const isDesktop = ref(!isMobileViewport() && !isTabletViewport())

	const updateViewport = () => {
		isMobile.value = isMobileViewport()
		isTablet.value = isTabletViewport()
		isDesktop.value = !isMobileViewport() && !isTabletViewport()
	}

	onMounted(() => {
		window.addEventListener('resize', updateViewport)
	})

	onUnmounted(() => {
		window.removeEventListener('resize', updateViewport)
	})

	return {
		isMobile,
		isTablet,
		isDesktop,
	}
}

/**
 * Vue composable for touch gestures
 * @return {object} Touch gesture handlers
 */
export function useTouchGestures() {
	const touchStart = ref({ x: 0, y: 0 })
	const touchEnd = ref({ x: 0, y: 0 })

	const onTouchStart = (event) => {
		touchStart.value = {
			x: event.touches[0].clientX,
			y: event.touches[0].clientY,
		}
	}

	const onTouchEnd = (event) => {
		touchEnd.value = {
			x: event.changedTouches[0].clientX,
			y: event.changedTouches[0].clientY,
		}
	}

	const getSwipeDirection = () => {
		const dx = touchEnd.value.x - touchStart.value.x
		const dy = touchEnd.value.y - touchStart.value.y
		const minSwipeDistance = 50

		if (Math.abs(dx) > Math.abs(dy)) {
			if (Math.abs(dx) > minSwipeDistance) {
				return dx > 0 ? 'right' : 'left'
			}
		} else {
			if (Math.abs(dy) > minSwipeDistance) {
				return dy > 0 ? 'down' : 'up'
			}
		}
		return null
	}

	return {
		onTouchStart,
		onTouchEnd,
		getSwipeDirection,
	}
}

/**
 * Prevent zoom on double tap for mobile
 */
export function preventDoubleTapZoom() {
	let lastTouchEnd = 0
	document.addEventListener('touchend', (event) => {
		const now = Date.now()
		if (now - lastTouchEnd <= 300) {
			event.preventDefault()
		}
		lastTouchEnd = now
	}, false)
}

/**
 * Scroll to element with mobile offset
 * @param {string} selector - Element selector
 * @param {number} offset - Additional offset
 */
export function scrollToElement(selector, offset = 80) {
	const element = document.querySelector(selector)
	if (element) {
		const elementPosition = element.getBoundingClientRect().top + window.pageYOffset
		const offsetPosition = elementPosition - offset

		window.scrollTo({
			top: offsetPosition,
			behavior: 'smooth',
		})
	}
}
