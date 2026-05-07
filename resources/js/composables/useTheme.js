import { ref, onMounted, onUnmounted } from 'vue'

const isDark = ref(false)

function applyTheme(dark) {
    isDark.value = dark
    document.documentElement.classList.toggle('dark', dark)
}

export function useTheme() {
    let mediaQuery = null
    let systemChangeHandler = null

    onMounted(() => {
        isDark.value = document.documentElement.classList.contains('dark')

        mediaQuery = window.matchMedia('(prefers-color-scheme: dark)')
        systemChangeHandler = (e) => {
            if (!localStorage.getItem('theme')) {
                applyTheme(e.matches)
            }
        }
        mediaQuery.addEventListener('change', systemChangeHandler)
    })

    onUnmounted(() => {
        if (mediaQuery && systemChangeHandler) {
            mediaQuery.removeEventListener('change', systemChangeHandler)
        }
    })

    function toggle() {
        const next = !isDark.value
        applyTheme(next)
        localStorage.setItem('theme', next ? 'dark' : 'light')
    }

    return { isDark, toggle }
}
