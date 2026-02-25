/**
 * Script to download Microsoft Fluent UI Emojis for ChoreStrike
 *
 * Usage: node scripts/download-emojis.js
 *
 * This script downloads the essential emojis for rooms, tasks, monsters,
 * and gamification from the Microsoft Fluent UI Emoji repository.
 *
 * @see https://github.com/microsoft/fluentui-emoji
 */

const https = require('https')
const fs = require('fs')
const path = require('path')

const EMOJIS_TO_DOWNLOAD = {
	// Rooms
	rooms: [
		'couch_and_lamp',     // Living room
		'bed',                // Bedroom
		'bathtub',            // Bathroom
		'cooking',            // Kitchen
		'potted_plant',       // Balcony/Garden
		'laptop',             // Office
		'automobile',         // Garage
		'package',            // Storage
		'fork_and_knife_with_plate', // Dining
	],

	// Tasks & Chores
	tasks: [
		'wastebasket',        // Trash
		'broom',              // Cleaning
		'sponge',             // Sponge
		'bucket',             // Bucket
		'soap',               // Soap
		'basket',             // Laundry
		'shopping_cart',      // Shopping
		'hammer_and_wrench',  // Repair
	],

	// Gamification - Energy
	energy: [
		'high_voltage',       // Energy
		'battery',            // Battery
		'electric_plug',      // Power
	],

	// Gamification - Rewards
	rewards: [
		'star',               // Star
		'sparkles',           // Sparks
		'coin',               // Coin
		'gem_stone',          // Points
	],

	// Gamification - Streak
	streak: [
		'fire',               // Fire/Streak
	],

	// Gamification - Medals
	medals: [
		'trophy',             // Trophy
		'sports_medal',       // Medal (generic)
		'crown',              // Crown
		'first_place_medal',  // Gold
		'second_place_medal', // Silver
		'third_place_medal',  // Bronze
	],

	// Monsters
	monsters: [
		'space_invader',      // Alien/Monster
		'microbe',            // Bacteria
		'ghost',              // Ghost
		'spider_web',         // Spider web
		'dashing_away',       // Dust
	],

	// Avatars (Animals)
	avatars: [
		'fox',                // Fox
		'panda',              // Panda
		'cat_face',           // Cat
		'frog',               // Frog
		'dog_face',           // Dog
		'owl',                // Owl
		'rabbit_face',        // Rabbit
		'bear',               // Bear
		'penguin',            // Penguin
		'unicorn',            // Unicorn
		'dragon_face',        // Dragon
		'robot',              // Robot
	],

	// Status
	status: [
		'check_box_with_check',     // Done/Check
		'white_medium_circle',      // Pending
		'warning',                  // Warning
		'cross_mark',               // Error
		'information',              // Info
	],

	// Time
	time: [
		'alarm_clock',              // Clock
		'sunrise',                  // Morning
		'sun',                      // Afternoon
		'cityscape_at_dusk',        // Evening
		'night_with_stars',         // Night
	],
}

const STYLE = '3d' // Options: '3d', 'color', 'flat', 'high-contrast'
const BASE_URL = 'https://raw.githubusercontent.com/microsoft/fluentui-emoji/main/assets'
const OUTPUT_DIR = path.join(__dirname, '..', 'img', 'emojis')

/**
 * Download a single emoji
 *
 * @param {string} emojiName - The emoji name
 * @returns {Promise<void>}
 */
function downloadEmoji(emojiName) {
	const url = `${BASE_URL}/${emojiName}/${STYLE}/${emojiName}_${STYLE}.png`
	const outputPath = path.join(OUTPUT_DIR, `${emojiName}.png`)

	return new Promise((resolve, reject) => {
		// Check if already exists
		if (fs.existsSync(outputPath)) {
			console.log(`  ✓ ${emojiName} (already exists)`)
			resolve()
			return
		}

		const file = fs.createWriteStream(outputPath)

		https.get(url, (response) => {
			if (response.statusCode === 200) {
				response.pipe(file)
				file.on('finish', () => {
					file.close()
					console.log(`  ✓ ${emojiName}`)
					resolve()
				})
			} else if (response.statusCode === 404) {
				file.close()
				fs.unlinkSync(outputPath)
				console.log(`  ✗ ${emojiName} (not found - may need different name)`)
				resolve() // Continue even if one fails
			} else {
				file.close()
				fs.unlinkSync(outputPath)
				reject(new Error(`Failed to download ${emojiName}: ${response.statusCode}`))
			}
		}).on('error', (err) => {
			file.close()
			if (fs.existsSync(outputPath)) {
				fs.unlinkSync(outputPath)
			}
			reject(err)
		})
	})
}

/**
 * Download all emojis
 */
async function downloadAllEmojis() {
	console.log('📦 Downloading Microsoft Fluent UI Emojis for ChoreStrike...')
	console.log(`Style: ${STYLE}`)
	console.log(`Output: ${OUTPUT_DIR}`)
	console.log('')

	// Create output directory if it doesn't exist
	if (!fs.existsSync(OUTPUT_DIR)) {
		fs.mkdirSync(OUTPUT_DIR, { recursive: true })
	}

	const allEmojis = Object.values(EMOJIS_TO_DOWNLOAD).flat()
	const uniqueEmojis = [...new Set(allEmojis)]

	console.log(`Total emojis to download: ${uniqueEmojis.length}`)
	console.log('')

	let successCount = 0
	let failCount = 0

	for (const category of Object.keys(EMOJIS_TO_DOWNLOAD)) {
		console.log(`📁 ${category}:`)
		const emojis = EMOJIS_TO_DOWNLOAD[category]

		for (const emoji of emojis) {
			try {
				await downloadEmoji(emoji)
				successCount++
			} catch (err) {
				console.log(`  ✗ ${emoji} (error: ${err.message})`)
				failCount++
			}
		}
		console.log('')
	}

	console.log('✅ Done!')
	console.log(`  Success: ${successCount}/${uniqueEmojis.length}`)
	console.log(`  Failed: ${failCount}/${uniqueEmojis.length}`)
	console.log('')
	console.log('📝 Next steps:')
	console.log('  1. Add attribution to README:')
	console.log('     "Emoji graphics by Microsoft Fluent UI Emoji (MIT License)"')
	console.log('  2. Update components to use <Emoji name="..." />')
	console.log('  3. Set :local="true" in Emoji component for production')
}

// Run the download
downloadAllEmojis().catch(console.error)
