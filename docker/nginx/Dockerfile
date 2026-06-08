FROM public.ecr.aws/docker/library/node:22-bookworm-slim AS frontend-build

WORKDIR /workspace

COPY package.json package-lock.json .npmrc ./
COPY apps/frontend/package.json apps/frontend/package.json
RUN npm ci --ignore-scripts

COPY openapi openapi
COPY apps/frontend apps/frontend
RUN npm run openapi:generate \
    && npm run frontend:build

FROM public.ecr.aws/docker/library/nginx:1.27-alpine

RUN rm -f /etc/nginx/conf.d/default.conf \
    && mkdir -p /run /var/cache/nginx /var/run /var/www/html/public \
    && chown -R nginx:nginx /run /var/cache/nginx /var/run /var/www/html

COPY docker/nginx/default.conf /etc/nginx/conf.d/default.conf
COPY public/index.php /var/www/html/public/index.php
COPY public/favicon.ico /var/www/html/public/favicon.ico
COPY public/robots.txt /var/www/html/public/robots.txt
COPY --from=frontend-build /workspace/apps/frontend/dist /var/www/html/public

USER nginx
