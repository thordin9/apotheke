-- The one live installation, with the names it already has (edge/src/installation.ts INSTALLATION_NAME, edge/src/r2.ts
-- PREFIX, the container's getByName in edge/src/index.ts). Plan internal has no limit rows, so no limits.
INSERT INTO plans (id, name) VALUES ('internal', 'Internal');
INSERT INTO installations (id, do_name, r2_prefix, status) VALUES ('primary', 'primary', 'installations/primary/', 'active');
INSERT INTO installation_routes (host, installation_id) VALUES ('api.services.getapotheke.com', 'primary');
INSERT INTO subscriptions (installation_id, plan_id, status) VALUES ('primary', 'internal', 'active');
