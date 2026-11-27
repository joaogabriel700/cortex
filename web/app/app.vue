<script setup lang="ts">
// Placeholder only. Screens come from the approved prototypes.
const { t, locale, locales, setLocale } = useI18n()

const apiStatus = ref<ApiStatus | 'checking'>('checking')

onMounted(async () => {
  apiStatus.value = await fetchApiStatus(() => $fetch('/api/health'))
})

useHead({ htmlAttrs: { lang: locale } })
</script>

<template>
  <div>
    <NuxtPwaManifest />
    <main>
      <h1>Cortex</h1>
      <p>{{ t('scaffold.tagline') }}</p>
      <p data-testid="api-status">{{ t('scaffold.api') }}: {{ t(`scaffold.status.${apiStatus}`) }}</p>
      <nav :aria-label="t('scaffold.language')">
        <button
          v-for="item in locales"
          :key="item.code"
          type="button"
          :aria-pressed="item.code === locale"
          @click="setLocale(item.code)"
        >
          {{ item.name }}
        </button>
      </nav>
    </main>
  </div>
</template>
