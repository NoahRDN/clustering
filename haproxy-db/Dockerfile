FROM haproxy:2.9

USER root

RUN set -eux; \
    mkdir -p /var/run/haproxy && \
    chown -R haproxy:haproxy /var/run/haproxy && \
    chmod 770 /var/run/haproxy

COPY --chmod=755 haproxy-entrypoint.sh /usr/local/bin/haproxy-entrypoint-custom.sh

ENTRYPOINT ["/usr/local/bin/haproxy-entrypoint-custom.sh"]
