-- Crear usuarios @nexo.edu con password admin123
-- Hash bcrypt para admin123: $2y$12$aHJm3fmaavJpHwmTC0fq1OyaBla2Nj.Rvxc9uWnPuYy.SFTXiu9My

WITH rector_role AS (SELECT role_id FROM roles WHERE role_name='RECTOR' LIMIT 1)
INSERT INTO users(user_id, school_id, role_id, document_number, first_name, last_name, email, phone, password_hash, password_salt, active, created_at)
SELECT gen_random_uuid(),'a3333333-3333-3333-3333-333333333333'::UUID,role_id,'100000001','Rector','NEXO','rector@nexo.edu','3000000001','$2y$12$aHJm3fmaavJpHwmTC0fq1OyaBla2Nj.Rvxc9uWnPuYy.SFTXiu9My','salt_rector',TRUE,NOW() FROM rector_role ON CONFLICT(email) DO NOTHING;

WITH coord_role AS (SELECT role_id FROM roles WHERE role_name='COORDINADOR' LIMIT 1)
INSERT INTO users(user_id, school_id, role_id, document_number, first_name, last_name, email, phone, password_hash, password_salt, active, created_at)
SELECT gen_random_uuid(),'a3333333-3333-3333-3333-333333333333'::UUID,role_id,'100000002','Coordinador','NEXO','coordinador@nexo.edu','3000000002','$2y$12$aHJm3fmaavJpHwmTC0fq1OyaBla2Nj.Rvxc9uWnPuYy.SFTXiu9My','salt_coordinador',TRUE,NOW() FROM coord_role ON CONFLICT(email) DO NOTHING;

WITH docente_role AS (SELECT role_id FROM roles WHERE role_name='DOCENTE' LIMIT 1)
INSERT INTO users(user_id, school_id, role_id, document_number, first_name, last_name, email, phone, password_hash, password_salt, active, created_at)
SELECT gen_random_uuid(),'a3333333-3333-3333-3333-333333333333'::UUID,role_id,'100000003','Docente','NEXO','docente@nexo.edu','3000000003','$2y$12$aHJm3fmaavJpHwmTC0fq1OyaBla2Nj.Rvxc9uWnPuYy.SFTXiu9My','salt_docente',TRUE,NOW() FROM docente_role ON CONFLICT(email) DO NOTHING;

WITH psico_role AS (SELECT role_id FROM roles WHERE role_name='PSICORIENTADOR' LIMIT 1)
INSERT INTO users(user_id, school_id, role_id, document_number, first_name, last_name, email, phone, password_hash, password_salt, active, created_at)
SELECT gen_random_uuid(),'a3333333-3333-3333-3333-333333333333'::UUID,role_id,'100000004','Psicorientador','NEXO','psicorientador@nexo.edu','3000000004','$2y$12$aHJm3fmaavJpHwmTC0fq1OyaBla2Nj.Rvxc9uWnPuYy.SFTXiu9My','salt_psicorientador',TRUE,NOW() FROM psico_role ON CONFLICT(email) DO NOTHING;

WITH secretaria_role AS (SELECT role_id FROM roles WHERE role_name='SECRETARIA' LIMIT 1)
INSERT INTO users(user_id, school_id, role_id, document_number, first_name, last_name, email, phone, password_hash, password_salt, active, created_at)
SELECT gen_random_uuid(),'a3333333-3333-3333-3333-333333333333'::UUID,role_id,'100000005','Secretaria','NEXO','secretaria@nexo.edu','3000000005','$2y$12$aHJm3fmaavJpHwmTC0fq1OyaBla2Nj.Rvxc9uWnPuYy.SFTXiu9My','salt_secretaria',TRUE,NOW() FROM secretaria_role ON CONFLICT(email) DO NOTHING;

WITH portero_role AS (SELECT role_id FROM roles WHERE role_name='PORTERO' LIMIT 1)
INSERT INTO users(user_id, school_id, role_id, document_number, first_name, last_name, email, phone, password_hash, password_salt, active, created_at)
SELECT gen_random_uuid(),'a3333333-3333-3333-3333-333333333333'::UUID,role_id,'100000006','Portero','NEXO','portero@nexo.edu','3000000006','$2y$12$aHJm3fmaavJpHwmTC0fq1OyaBla2Nj.Rvxc9uWnPuYy.SFTXiu9My','salt_portero',TRUE,NOW() FROM portero_role ON CONFLICT(email) DO NOTHING;

WITH auxiliar_role AS (SELECT role_id FROM roles WHERE role_name='AUXILIAR' LIMIT 1)
INSERT INTO users(user_id, school_id, role_id, document_number, first_name, last_name, email, phone, password_hash, password_salt, active, created_at)
SELECT gen_random_uuid(),'a3333333-3333-3333-3333-333333333333'::UUID,role_id,'100000007','Auxiliar','NEXO','auxiliar@nexo.edu','3000000007','$2y$12$aHJm3fmaavJpHwmTC0fq1OyaBla2Nj.Rvxc9uWnPuYy.SFTXiu9My','salt_auxiliar',TRUE,NOW() FROM auxiliar_role ON CONFLICT(email) DO NOTHING;
