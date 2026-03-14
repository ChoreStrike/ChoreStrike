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
		'Couch and lamp',     // Living room
		'Bed',                // Bedroom
		'Bathtub',            // Bathroom
		'Cooking',            // Kitchen
		'Potted plant',       // Balcony/Garden
		'Laptop',             // Office
		'Automobile',         // Garage
		'Package',            // Storage
		'Fork and knife with plate', // Dining
	],

	// Tasks & Chores
	tasks: [
		'Wastebasket',        // Trash
		'Broom',              // Cleaning
		'Sponge',             // Sponge
		'Bucket',             // Bucket
		'Soap',               // Soap
		'Basket',             // Laundry
		'Shopping cart',      // Shopping
		'Hammer and wrench',  // Repair
	],

	// Gamification - Energy
	energy: [
		'High voltage',       // Energy
		'Battery',            // Battery
		'Electric plug',      // Power
	],

	// Gamification - Rewards
	rewards: [
		'Star',               // Star
		'Sparkles',           // Sparks
		'Coin',               // Coin
		'Gem stone',          // Points
	],

	// Gamification - Streak
	streak: [
		'Fire',               // Fire/Streak
	],

	// Gamification - Medals
	medals: [
		'Trophy',             // Trophy
		'Sports medal',       // Medal (generic)
		'Crown',              // Crown
		'1st place medal',    // Gold
		'2nd place medal',    // Silver
		'3rd place medal',    // Bronze
	],

	// Monsters
	monsters: [
		'Alien monster',      // Alien/Monster
		'Microbe',            // Bacteria
		'Ghost',              // Ghost
		'Spider',             // Spider
		'Spider web',         // Spider web
		'Dashing',            // Dust (Dashing away)
	],

	// Avatars (Animals)
	avatars: [
		'Fox',                // Fox
		'Panda',              // Panda
		'Cat',                // Cat
		'Frog',               // Frog
		'Dog',                // Dog
		'Owl',                // Owl
		'Rabbit',             // Rabbit
		'Bear',               // Bear
		'Penguin',            // Penguin
		'Unicorn',            // Unicorn
		'Dragon',             // Dragon
		'Robot',              // Robot
	],

	// Status
	status: [
		'Check mark button',     // Done/Check
		'White circle',      // Pending
		'Warning',           // Warning
		'Cross mark',        // Error
		'Information',       // Info
	],

	// Time
	time: [
		'Alarm clock',              // Clock
		'Sunrise',                  // Morning
		'Sun',                      // Afternoon
		'Cityscape',                // Evening
		'Night with stars',         // Night
	],
}

const STYLE = '3D' // Options: '3D', 'Color', 'Flat', 'High Contrast'
const BASE_URL = 'https://raw.githubusercontent.com/microsoft/fluentui-emoji/main/assets'
const OUTPUT_DIR = path.join(__dirname, '..', 'img', 'emojis')

/**
 * Download a single emoji
 *
 * @param {string} emojiName - The emoji name
 * @returns {Promise<void>}
 */
function downloadEmoji(emojiName) {
	// Convert emoji name to URL-safe format (spaces become %20)
	const urlSafeName = encodeURIComponent(emojiName)
	const url = `${BASE_URL}/${urlSafeName}/${STYLE}/${urlSafeName}_${STYLE}.png`
	// Convert emoji name to file-safe format (spaces become underscores)
	const fileSafeName = emojiName.toLowerCase().replace(/ /g, '_')
	const outputPath = path.join(OUTPUT_DIR, `${fileSafeName}.png`)

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
