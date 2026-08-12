// Targeted suppression of the Node.js DEP0040 (punycode) deprecation warning.
//
// The warning is emitted by a transitive dependency — not project code — once
// per Jest worker and floods the test output (~30+ lines across 66 suites).
// Only DEP0040 is silenced; every other deprecation stays visible.
//
// This runs as a Jest `setupFiles` entry (before the test framework loads),
// because the warning fires during early module loading. `--disable-warning`
// is Node >=21 only, but `engines` declares `^20.0.0`, so overriding
// `process.emitWarning` keeps the Node-20 floor valid.

const originalEmitWarning = process.emitWarning.bind(process)

process.emitWarning = function (warning, options, code, ctor) {
	// process.emitWarning has two call shapes:
	//   emitWarning(warning, { type, code, detail, ctor })  -> options is object
	//   emitWarning(warning, type, code, ctor)               -> options is string
	const resolvedCode = typeof options === 'object' && options !== null
		? options.code
		: code

	if (resolvedCode === 'DEP0040') {
		return
	}

	if (typeof options === 'object' && options !== null) {
		return originalEmitWarning(warning, options)
	}
	if (code !== undefined || ctor !== undefined) {
		return originalEmitWarning(warning, options, code, ctor)
	}
	return originalEmitWarning(warning, options)
}
