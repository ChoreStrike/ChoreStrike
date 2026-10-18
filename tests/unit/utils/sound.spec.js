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

	it('is silent by default — the personal opt-in gates all playback (C5g)', () => {
		soundModule = require('@/utils/sound')
		soundModule.playSound('complete')

		expect(mockContext.createOscillator).not.toHaveBeenCalled()
		expect(soundModule.isSoundEnabled()).toBe(false)
	})

	it('plays a sound with the correct profile after the explicit opt-in', () => {
		soundModule = require('@/utils/sound')
		soundModule.setSoundEnabled(true)
		soundModule.playSound('complete')

		expect(mockContext.createOscillator).toHaveBeenCalled()
		expect(mockOscillator.start).toHaveBeenCalled()
		expect(mockOscillator.stop).toHaveBeenCalled()
	})

	it('does not play when muted, even with the opt-in set', () => {
		soundModule = require('@/utils/sound')
		soundModule.setSoundEnabled(true)
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

	it('a revoked opt-in silences the next effect', () => {
		soundModule = require('@/utils/sound')
		soundModule.setSoundEnabled(true)
		soundModule.setSoundEnabled(false)
		soundModule.playSound('complete')

		expect(mockContext.createOscillator).not.toHaveBeenCalled()
	})

	it('ignores unknown sound names', () => {
		soundModule = require('@/utils/sound')
		soundModule.setSoundEnabled(true)
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

	describe('arcade profiles (CS-PLAN-086 WP-G)', () => {
		/** A context that also offers buffers and filters for noise bursts. */
		const withNoise = () => {
			const source = { buffer: null, connect: jest.fn(), start: jest.fn(), stop: jest.fn() }
			const filter = { type: '', frequency: { value: 0 }, Q: { value: 0 }, connect: jest.fn() }
			Object.assign(mockContext, {
				sampleRate: 48000,
				createBuffer: jest.fn(() => ({ getChannelData: () => new Float32Array(12000) })),
				createBufferSource: jest.fn(() => source),
				createBiquadFilter: jest.fn(() => filter),
			})
			return { source, filter }
		}

		it('names every profile the arcade plays', () => {
			soundModule = require('@/utils/sound')
			expect(soundModule.SOUND_NAMES).toEqual(expect.arrayContaining([
				'pop', 'swish', 'start', 'bounce', 'miss', 'crash', 'cleared', 'penalty', 'flip', 'wrong',
			]))
		})

		it('plays every note of a multi-note profile at its offset with its own gain', () => {
			soundModule = require('@/utils/sound')
			soundModule.setSoundEnabled(true)

			soundModule.playSound('cleared')
			expect(mockContext.createOscillator).toHaveBeenCalledTimes(4)
			expect(mockOscillator.start.mock.calls.map(([at]) => at)).toEqual([0, 0.09, 0.18, 0.27])
			expect(mockOscillator.stop.mock.calls.at(-1)[0]).toBeCloseTo(0.47)

			soundModule.playSound('crash')
			expect(mockOscillator.type).toBe('sawtooth')
			expect(mockGain.gain.setValueAtTime).toHaveBeenLastCalledWith(0.16, 0)
		})

		it('skips the noise burst on a context without buffers and still plays the tone', () => {
			soundModule = require('@/utils/sound')
			soundModule.setSoundEnabled(true)

			soundModule.playSound('bounce')

			expect(mockContext.createOscillator).toHaveBeenCalledTimes(1)
			expect(mockContext.createGain).toHaveBeenCalledTimes(1)
		})

		it('band-passes a burst of noise through a filter where the context supports it', () => {
			const { source, filter } = withNoise()
			soundModule = require('@/utils/sound')
			soundModule.setSoundEnabled(true)

			soundModule.playSound('bounce')
			soundModule.playSound('flip')

			expect(mockContext.createBuffer).toHaveBeenCalledTimes(1)
			expect(mockContext.createBufferSource).toHaveBeenCalledTimes(2)
			expect(filter.type).toBe('bandpass')
			expect(filter.frequency.value).toBe(2500)
			expect(source.connect).toHaveBeenCalledWith(filter)
			expect(filter.connect).toHaveBeenCalledWith(mockGain)
			expect(source.start).toHaveBeenCalledWith(0)
			expect(source.stop).toHaveBeenLastCalledWith(0.05)
		})
	})
})
