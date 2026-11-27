#!/usr/bin/env bash
# Creates the application roles and the database. Runs once, on an empty data volume,
# as the container superuser. The application never connects as the superuser.
# Passwords are read by psql from the environment, so they do not appear in process arguments.
set -euo pipefail

: "${APP_DB_NAME:?}"
APP_OWNER_PASSWORD="$(cat "${APP_OWNER_PASSWORD_FILE:?}")"
APP_RUNTIME_PASSWORD="$(cat "${APP_RUNTIME_PASSWORD_FILE:?}")"
APP_MAINTENANCE_PASSWORD="$(cat "${APP_MAINTENANCE_PASSWORD_FILE:?}")"
export APP_OWNER_PASSWORD APP_RUNTIME_PASSWORD APP_MAINTENANCE_PASSWORD

psql -v ON_ERROR_STOP=1 --username "$POSTGRES_USER" --dbname postgres <<'SQL'
\getenv db_name APP_DB_NAME
\getenv owner_password APP_OWNER_PASSWORD
\getenv runtime_password APP_RUNTIME_PASSWORD
\getenv maintenance_password APP_MAINTENANCE_PASSWORD

CREATE ROLE app_owner LOGIN NOSUPERUSER NOBYPASSRLS NOCREATEROLE NOCREATEDB PASSWORD :'owner_password';
CREATE ROLE app_auth NOLOGIN NOSUPERUSER NOBYPASSRLS NOCREATEROLE NOCREATEDB;
CREATE ROLE app_runtime LOGIN NOSUPERUSER NOBYPASSRLS NOCREATEROLE NOCREATEDB PASSWORD :'runtime_password';
CREATE ROLE app_maintenance LOGIN NOSUPERUSER NOBYPASSRLS NOCREATEROLE NOCREATEDB PASSWORD :'maintenance_password';

GRANT app_auth TO app_owner WITH INHERIT FALSE, SET TRUE;

CREATE DATABASE :"db_name" OWNER app_owner;
-- REVOKE ALL covers CONNECT and TEMPORARY for PUBLIC. No ALTER DEFAULT PRIVILEGES anywhere:
-- every table gets explicit grants in its migration.
REVOKE ALL ON DATABASE :"db_name" FROM PUBLIC;
-- No application role may create temporary objects, the owner included.
REVOKE TEMPORARY ON DATABASE :"db_name" FROM app_owner, app_auth, app_runtime, app_maintenance;
GRANT CONNECT ON DATABASE :"db_name" TO app_runtime, app_maintenance;
-- The other databases are of no use to the application roles either.
REVOKE CONNECT, TEMPORARY ON DATABASE postgres, template1 FROM PUBLIC;

\connect :"db_name"
REVOKE CREATE ON SCHEMA public FROM PUBLIC;
SQL
