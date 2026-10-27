# MariaDB for the Document Tracking System.
# Scripts in initdb/ run once, the first time the data volume is empty.
FROM mariadb:11.4

COPY initdb/ /docker-entrypoint-initdb.d/
