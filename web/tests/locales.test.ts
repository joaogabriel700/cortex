import { describe, expect, it } from 'vitest'
import en from '../i18n/locales/en.json'
import ptBR from '../i18n/locales/pt-BR.json'

function keys(value: object, prefix = ''): string[] {
  return Object.entries(value)
    .flatMap(([key, child]) =>
      typeof child === 'object' && child !== null ? keys(child, `${prefix}${key}.`) : [`${prefix}${key}`],
    )
    .sort()
}

describe('translations', () => {
  it('have the same keys in pt-BR and en', () => {
    expect(keys(ptBR)).toEqual(keys(en))
  })
})
