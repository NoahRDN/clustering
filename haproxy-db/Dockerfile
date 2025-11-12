FROM haproxy:2.9

USER root
RUN apt-get update \
    && apt-get install -y --no-install-recommends curl \
    && rm -rf /var/lib/apt/lists/*

COPY haproxy-entrypoint.sh /usr/local/bin/haproxy-entrypoint.sh
RUN chmod +x /usr/local/bin/haproxy-entrypoint.sh

ENTRYPOINT ["/usr/local/bin/haproxy-entrypoint.sh"]
CMD ["haproxy", "-f", "/usr/local/etc/haproxy/haproxy.cfg", "-db"]
