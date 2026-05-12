import preset from '../../../../vendor/filament/filament/tailwind.config.preset.js'

export default {
    presets: [preset],
    content: [
        '../../../../app/Filament/**/*.php',
        '../../../../resources/views/filament/**/*.blade.php',
        '../../../../vendor/filament/**/*.blade.php',
    ],
    theme: {
        extend: {
            fontFamily: {
                sans: ['Avenir Next', 'Segoe UI', 'Arial', 'sans-serif'],
            },
        },
    },
}
