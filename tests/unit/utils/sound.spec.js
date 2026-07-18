describe('sound utility', () => {
	let soundModule
	let mockOscillator
	let mockGain
	let mockContext

	beforeEach(() => {
		jest.resetModules()

		mockOscillator = {
			type: 'sine',
			frequency: { setValueAtTime: jest.fn(), linearRampToValueAtTime: jest.fn() },
			connect: jest.fn(),
			start: jest.fn(),
			stop: jest.fn(),
		}
		mockGain = {
			gain: { setValueAtTime: jest.fn(), exponentialRampToValueAtTime: jest.fn() },
			connect: jest.fn(),
		}
		mockContext = {
			state: 'running',
			currentTime: 0,
			destination: {},
			createOscillator: jest.fn().mockReturnValue(mockOscillator),
			createGain: jest.fn().mockReturnValue(mockGain),
			resume: jest.fn(),
		}

		global.AudioContext = jest.fn().mockImplementation(() => mockContext)
		global.webkitAudioContext = undefined
	})

	afterEach(() => {
		jest.restoreAllMocks()
		delete global.AudioContext
		delete global.webkitAudioContext
	})

	it('plays a sound with the correct profile', () => {
		soundModule = require('@/utils/sound')
		soundModule.playSound('complete')

		expect(mockContext.createOscillator).toHaveBeenCalled()
		expect(mockOscillator.start).toHaveBeenCalled()
		expect(mockOscillator.stop).toHaveBeenCalled()
	})

	it('does not play when muted', () => {
		soundModule = require('@/utils/sound')
		soundModule.setMuted(true)
		soundModule.playSound('complete')

		expect(mockContext.createOscillator).not.toHaveBeenCalled()
	})

	it('setMuted and isMuted toggle correctly', () => {
		soundModule = require('@/utils/sound')
		expect(soundModule.isMuted()).toBe(false)

		soundModule.setMuted(true)
		expect(soundModule.isMuted()).toBe(true)

		soundModule.setMuted(false)
		expect(soundModule.isMuted()).toBe(false)
	})

	it('ignores unknown sound names', () => {
		soundModule = require('@/utils/sound')
		soundModule.playSound('nonexistent')

		expect(mockContext.createOscillator).not.toHaveBeenCalled()
	})

	it('unlockAudio resumes suspended context', () => {
		mockContext.state = 'suspended'
		soundModule = require('@/utils/sound')
		soundModule.unlockAudio()

		expect(mockContext.resume).toHaveBeenCalled()
	})

	it('preloadSounds is a no-op for synthesised sounds', () => {
		soundModule = require('@/utils/sound')
		expect(() => soundModule.preloadSounds()).not.toThrow()
	})
})
