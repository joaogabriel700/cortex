# syntax=docker/dockerfile:1
FROM node:24-alpine AS base
WORKDIR /app
RUN chown node:node /app
USER node

FROM base AS deps
COPY --chown=node:node package.json package-lock.json ./
RUN npm ci --ignore-scripts && npm rebuild

FROM deps AS dev
COPY --chown=node:node . .
RUN npx nuxt prepare
EXPOSE 3000
CMD ["npm", "run", "dev", "--", "--host", "0.0.0.0", "--port", "3000"]

FROM dev AS test

FROM test AS build
RUN npm run generate && node scripts/csp.mjs .output/public /app/csp.caddy

FROM caddy:2-alpine AS prod
RUN apk add --no-cache libcap \
    && setcap -r /usr/bin/caddy \
    && apk del libcap \
    && adduser -D -H -u 10001 app \
    && chown -R app /data /config
COPY Caddyfile /etc/caddy/Caddyfile
COPY --from=build /app/csp.caddy /etc/caddy/csp.caddy
COPY --from=build /app/.output/public /srv
USER app
EXPOSE 8080 8443
