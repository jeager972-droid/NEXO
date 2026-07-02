-- NEXO SEED DATA - Ejecutar: psql $DATABASE_URL -f seed_data_nexo.sql

INSERT INTO departments(department_id, department_name) VALUES('d1111111-1111-1111-1111-111111111111'::UUID,'Cundinamarca') ON CONFLICT DO NOTHING;
INSERT INTO municipalities(municipality_id, department_id, municipality_name) VALUES('a2222222-2222-2222-2222-222222222222'::UUID,'d1111111-1111-1111-1111-111111111111'::UUID,'Bogotá D.C.') ON CONFLICT DO NOTHING;
INSERT INTO schools(school_id, municipality_id, dane_code, school_name, address, phone, email, active) VALUES('a3333333-3333-3333-3333-333333333333'::UUID,'a2222222-2222-2222-2222-222222222222'::UUID,'111001000001','Colegio Nacional','Carrera 7 # 32-12','6012345678','contacto@colegionacional.edu.co',TRUE) ON CONFLICT DO NOTHING;

INSERT INTO roles(role_id, role_name, description) VALUES (gen_random_uuid(),'SUPER_RECTOR','Super administrador'),(gen_random_uuid(),'RECTOR','Director'),(gen_random_uuid(),'COORDINADOR','Coordinador'),(gen_random_uuid(),'DOCENTE','Docente'),(gen_random_uuid(),'SECRETARIA','Secretaria'),(gen_random_uuid(),'PORTERO','Portero'),(gen_random_uuid(),'AUXILIAR','Auxiliar'),(gen_random_uuid(),'PSICORIENTADOR','Psicorientador'),(gen_random_uuid(),'GUARDIAN','Acudiente') ON CONFLICT DO NOTHING;

INSERT INTO permissions(permission_id, permission_code, description) SELECT gen_random_uuid(),code,desc_text FROM (VALUES ('students.read','Ver estudiantes'),('students.write','Crear/editar'),('biometric.read','Ver biométricos'),('reports.read','Ver reportes'),('admin.full','Admin total'),('twilio.send','Enviar mensajes'),('devices.manage','Gestionar dispositivos')) AS t(code,desc_text) ON CONFLICT DO NOTHING;

WITH admin_role AS (SELECT role_id FROM roles WHERE role_name='SUPER_RECTOR' LIMIT 1)
INSERT INTO users(user_id, school_id, role_id, document_number, first_name, last_name, email, phone, password_hash, password_salt, active, created_at)
SELECT gen_random_uuid(),'a3333333-3333-3333-3333-333333333333'::UUID,role_id,'111111111','Administrador','NEXO','admin@nexo.edu','3000000000','$2y$12$aHJm3fmaavJpHwmTC0fq1OyaBla2Nj.Rvxc9uWnPuYy.SFTXiu9My','salt_admin',TRUE,NOW() FROM admin_role ON CONFLICT(email) DO NOTHING;

INSERT INTO users(user_id, school_id, role_id, document_number, first_name, last_name, email, phone, password_hash, password_salt, active, created_at)
SELECT gen_random_uuid(),'a3333333-3333-3333-3333-333333333333'::UUID,r.role_id,doc,fname,lname,email,'300'||(1000000+s)::TEXT,'$2y$12$aHJm3fmaavJpHwmTC0fq1OyaBla2Nj.Rvxc9uWnPuYy.SFTXiu9My','salt_docente',TRUE,NOW()
FROM (VALUES(1,'222222222','Carlos','Martinez','cmartinez@colegionacional.edu.co'),(2,'333333333','Ana','Rodriguez','arodriguez@colegionacional.edu.co'),(3,'444444444','Luis','Gonzalez','lgonzalez@colegionacional.edu.co'),(4,'555555555','Maria','Lopez','mlopez@colegionacional.edu.co'),(5,'666666666','Pedro','Sanchez','psanchez@colegionacional.edu.co'),(6,'777777777','Jose','Ramirez','jramirez@colegionacional.edu.co'),(7,'888888888','Diana','Torres','dtorres@colegionacional.edu.co'),(8,'999999999','Camila','Castro','ccastro@colegionacional.edu.co')) AS t(s,doc,fname,lname,email)
JOIN roles r ON r.role_name='DOCENTE' ON CONFLICT(email) DO NOTHING;

INSERT INTO classrooms(classroom_id, school_id, classroom_name, building, created_at) VALUES('c1aaaaaa-aaaa-aaaa-aaaa-aaaaaaaaaaaa'::UUID,'a3333333-3333-3333-3333-333333333333'::UUID,'Salon 101','Bloque A',NOW()),('c2bbbbbb-bbbb-bbbb-bbbb-bbbbbbbbbbbb'::UUID,'a3333333-3333-3333-3333-333333333333'::UUID,'Salon 102','Bloque A',NOW()),('c3cccccc-cccc-cccc-cccc-cccccccccccc'::UUID,'a3333333-3333-3333-3333-333333333333'::UUID,'Salon 201','Bloque B',NOW()),('c4dddddd-dddd-dddd-dddd-dddddddddddd'::UUID,'a3333333-3333-3333-3333-333333333333'::UUID,'Salon 202','Bloque B',NOW()),('c5eeeeee-eeee-eeee-eeee-eeeeeeeeeeee'::UUID,'a3333333-3333-3333-3333-333333333333'::UUID,'Laboratorio','Bloque C',NOW()) ON CONFLICT DO NOTHING;

INSERT INTO academic_groups(group_id, school_id, group_name, grade_level, academic_year, created_at) VALUES('a10aaaaa-aaaa-aaaa-aaaa-aaaaaaaaaaaa'::UUID,'a3333333-3333-3333-3333-333333333333'::UUID,'6A','Sexto',2026,NOW()),('a20bbbbb-bbbb-bbbb-bbbb-bbbbbbbbbbbb'::UUID,'a3333333-3333-3333-3333-333333333333'::UUID,'6B','Sexto',2026,NOW()),('a30ccccc-cccc-cccc-cccc-cccccccccccc'::UUID,'a3333333-3333-3333-3333-333333333333'::UUID,'7A','Septimo',2026,NOW()),('a40ddddd-dddd-dddd-dddd-dddddddddddd'::UUID,'a3333333-3333-3333-3333-333333333333'::UUID,'7B','Septimo',2026,NOW()),('a50eeeee-eeee-eeee-eeee-eeeeeeeeeeee'::UUID,'a3333333-3333-3333-3333-333333333333'::UUID,'8A','Octavo',2026,NOW()) ON CONFLICT DO NOTHING;

INSERT INTO subjects(subject_id, subject_name, created_at) VALUES(gen_random_uuid(),'Matematicas',NOW()),(gen_random_uuid(),'Ciencias',NOW()),(gen_random_uuid(),'Lengua',NOW()),(gen_random_uuid(),'Historia',NOW()),(gen_random_uuid(),'Educacion Fisica',NOW()),(gen_random_uuid(),'Ingles',NOW()),(gen_random_uuid(),'Tecnologia',NOW()) ON CONFLICT DO NOTHING;

DO $$
DECLARE i INT; groups UUID[]:=ARRAY['a10aaaaa-aaaa-aaaa-aaaa-aaaaaaaaaaaa'::UUID,'a20bbbbb-bbbb-bbbb-bbbb-bbbbbbbbbbbb'::UUID,'a30ccccc-cccc-cccc-cccc-cccccccccccc'::UUID,'a40ddddd-dddd-dddd-dddd-dddddddddddd'::UUID,'a50eeeee-eeee-eeee-eeee-eeeeeeeeeeee'::UUID];
fnames TEXT[]:=ARRAY['Jhon','Carlos','Ana','Luis','Maria','Pedro','Sofia','Diego','Laura','Juan','Valentina','Andres','Camila','Daniel','Juliana','Miguel','Natalia','Alejandro','Isabella','Mateo'];
lnames TEXT[]:=ARRAY['Edison','Garcia','Martinez','Rodriguez','Gonzalez','Lopez','Sanchez','Perez','Ramirez','Torres','Flores','Rivera','Castro','Ortiz','Reyes','Ruiz','Jimenez','Vargas','Moreno','Aguilar'];
BEGIN
FOR i IN 1..50 LOOP
INSERT INTO students(student_id,school_id,document_number,first_name,last_name,birth_date,active,created_at)
VALUES(CASE WHEN i=1 THEN 'a1111111-1111-1111-1111-111111111111'::UUID ELSE gen_random_uuid() END,'a3333333-3333-3333-3333-333333333333'::UUID,(100000000+i)::TEXT,CASE WHEN i=1 THEN 'Jhon' ELSE fnames[1+(i%20)] END,CASE WHEN i=1 THEN 'Edison' ELSE lnames[1+((i*3)%20)] END,CURRENT_DATE-INTERVAL'12 years'-((i%36)||' months')::INTERVAL,TRUE,NOW());
END LOOP;
END $$;

INSERT INTO student_group_assignments(assignment_id,student_id,group_id,active,start_date,created_at)
SELECT gen_random_uuid(),s.student_id,CASE WHEN s.document_number BETWEEN '100000001' AND '100000010' THEN 'a10aaaaa-aaaa-aaaa-aaaa-aaaaaaaaaaaa'::UUID WHEN s.document_number BETWEEN '100000011' AND '100000020' THEN 'a20bbbbb-bbbb-bbbb-bbbb-bbbbbbbbbbbb'::UUID WHEN s.document_number BETWEEN '100000021' AND '100000030' THEN 'a30ccccc-cccc-cccc-cccc-cccccccccccc'::UUID WHEN s.document_number BETWEEN '100000031' AND '100000040' THEN 'a40ddddd-dddd-dddd-dddd-dddddddddddd'::UUID ELSE 'a50eeeee-eeee-eeee-eeee-eeeeeeeeeeee'::UUID END,TRUE,'2026-01-20',NOW() FROM students s ON CONFLICT DO NOTHING;

DO $$
DECLARE i INT; gnames TEXT[]:=ARRAY['Carlos Sr.','Ana Maria','Luis Alberto','Maria Elena','Pedro Jose','Sofia Isabel','Diego Fernando','Laura Cristina','Juan Pablo','Valentina'];
v_user UUID; v_guard UUID; v_guard_role_id UUID;
BEGIN
v_guard_role_id:=(SELECT role_id FROM roles WHERE role_name='GUARDIAN' LIMIT 1);
FOR i IN 1..50 LOOP
v_user:=gen_random_uuid(); v_guard:=gen_random_uuid();
INSERT INTO users(user_id,school_id,role_id,document_number,first_name,last_name,email,phone,password_hash,password_salt,active,created_at)
VALUES(v_user,'a3333333-3333-3333-3333-333333333333'::UUID,v_guard_role_id,'g'||(100000000+i)::TEXT,gnames[1+(i%10)],'Acudiente '||i,'guardian'||i||'@test.com',CASE WHEN i=1 THEN '+573243607948' ELSE '+57'||(3000000000+i)::TEXT END,'$2y$12$aHJm3fmaavJpHwmTC0fq1OyaBla2Nj.Rvxc9uWnPuYy.SFTXiu9My','salt_guardian',TRUE,NOW()) ON CONFLICT(email) DO NOTHING;
IF FOUND THEN
INSERT INTO guardians(guardian_id,user_id,whatsapp_phone,emergency_contact,created_at)
VALUES(v_guard,v_user,CASE WHEN i=1 THEN '+573243607948' ELSE '+57'||(3000000000+i)::TEXT END,CASE WHEN i%5=0 THEN TRUE ELSE FALSE END,NOW()) ON CONFLICT(user_id) DO NOTHING;
END IF;
END LOOP;
END $$;

INSERT INTO guardian_student_relationships(relationship_id,guardian_id,student_id,relationship_type,primary_guardian,created_at)
SELECT gen_random_uuid(),g.guardian_id,s.student_id,'PADRE/MADRE',TRUE,NOW() FROM (SELECT ROW_NUMBER() OVER() rn,guardian_id FROM guardians ORDER BY guardian_id) g JOIN (SELECT ROW_NUMBER() OVER() rn,student_id FROM students ORDER BY student_id) s ON g.rn=s.rn ON CONFLICT DO NOTHING;

INSERT INTO edge_devices(device_id,school_id,classroom_id,device_name,active,created_at) VALUES(gen_random_uuid(),'a3333333-3333-3333-3333-333333333333'::UUID,'c1aaaaaa-aaaa-aaaa-aaaa-aaaaaaaaaaaa'::UUID,'Lector Entrada Principal',TRUE,NOW()),(gen_random_uuid(),'a3333333-3333-3333-3333-333333333333'::UUID,'c2bbbbbb-bbbb-bbbb-bbbb-bbbbbbbbbbbb'::UUID,'Lector Comedor',TRUE,NOW()),(gen_random_uuid(),'a3333333-3333-3333-3333-333333333333'::UUID,'c3cccccc-cccc-cccc-cccc-cccccccccccc'::UUID,'Lector Bloque B',TRUE,NOW()) ON CONFLICT DO NOTHING;

DO $$
DECLARE i INT; etypes TEXT[]:=ARRAY['INGRESO','SALIDA_ALMUERZO','REGRESO_ALMUERZO','SALIDA'];
eresults TEXT[]:=ARRAY['SUCCESS','SUCCESS','SUCCESS','LATE','ABSENT']; sids UUID[]; dids UUID[];
BEGIN SELECT array_agg(student_id::UUID) INTO sids FROM students WHERE school_id='a3333333-3333-3333-3333-333333333333'::UUID;
SELECT array_agg(device_id::UUID) INTO dids FROM edge_devices WHERE school_id='a3333333-3333-3333-3333-333333333333'::UUID;
FOR i IN 1..100 LOOP
INSERT INTO biometric_events(event_id,school_id,student_id,device_id,event_type,event_result,confidence_score,event_timestamp)
VALUES(gen_random_uuid(),'a3333333-3333-3333-3333-333333333333'::UUID,sids[1+((i*7)%array_length(sids,1))],dids[1+((i*3)%array_length(dids,1))],etypes[1+((i+1)%4)],eresults[1+(i%5)],85.0+(random()*14.9),CURRENT_DATE+((7+(i%10))::TEXT||':'||(15+(i%45))::TEXT||':00')::TIME);
END LOOP;
END $$;

INSERT INTO attendance_incidents(incident_id,school_id,student_id,incident_type,detected_at,resolved,created_at) SELECT gen_random_uuid(),'a3333333-3333-3333-3333-333333333333'::UUID,student_id,CASE WHEN random()<0.3 THEN 'LATE_ARRIVAL' WHEN random()<0.6 THEN 'EARLY_DEPARTURE' ELSE 'UNAUTHORIZED_ABSENCE' END,CURRENT_DATE+((8+(random()*6)::INT)::TEXT||':00:00')::TIME,CASE WHEN random()<0.5 THEN TRUE ELSE FALSE END,NOW() FROM students WHERE school_id='a3333333-3333-3333-3333-333333333333'::UUID ORDER BY random() LIMIT 15;

INSERT INTO sos_alerts(alert_id,school_id,emitted_by_user_id,classroom_id,alert_type,alert_description,resolved,emitted_at)
SELECT gen_random_uuid(),'a3333333-3333-3333-3333-333333333333'::UUID,(SELECT user_id FROM users WHERE email='admin@nexo.edu' LIMIT 1),classroom_id,CASE WHEN random()<0.33 THEN 'EMERGENCY_MEDICAL' WHEN random()<0.66 THEN 'SECURITY_THREAT' ELSE 'FIRE_ALARM' END,'Alerta simulada',TRUE,CURRENT_DATE+((10+(random()*4)::INT)::TEXT||':00:00')::TIME FROM classrooms WHERE school_id='a3333333-3333-3333-3333-333333333333'::UUID ORDER BY random() LIMIT 5;

INSERT INTO twilio_messages(twilio_message_id,school_id,student_id,guardian_id,sender_user_id,type_code,direction,phone_number,message_content,delivery_status,sent_at)
VALUES(gen_random_uuid(),'a3333333-3333-3333-3333-333333333333'::UUID,(SELECT student_id FROM students WHERE first_name='Jhon' AND last_name='Edison' LIMIT 1),(SELECT guardian_id FROM guardians WHERE whatsapp_phone='+573243607948' LIMIT 1),(SELECT user_id FROM users WHERE email='admin@nexo.edu' LIMIT 1),'CITATION','OUTBOUND','+573243607948','Estimado acudiente, su hijo Jhon Edison ha sido citado. Por favor comuniquese con la institucion.','SENT',NOW()) ON CONFLICT DO NOTHING;

INSERT INTO twilio_messages(twilio_message_id,school_id,student_id,guardian_id,sender_user_id,type_code,direction,phone_number,message_content,delivery_status,sent_at)
SELECT gen_random_uuid(),'a3333333-3333-3333-3333-333333333333'::UUID,s.student_id,gsr.guardian_id,(SELECT user_id FROM users WHERE email='admin@nexo.edu' LIMIT 1),'NOTIFICATION','OUTBOUND',g.whatsapp_phone,'Mensaje de prueba para '||s.first_name||' '||s.last_name,CASE WHEN random()<0.8 THEN 'SENT' ELSE 'FAILED' END,NOW()-(random()*INTERVAL'7 days') FROM students s JOIN guardian_student_relationships gsr ON gsr.student_id=s.student_id JOIN guardians g ON g.guardian_id=gsr.guardian_id WHERE s.school_id='a3333333-3333-3333-3333-333333333333'::UUID ORDER BY random() LIMIT 30;

INSERT INTO user_commands(command_id,school_id,executed_by_user_id,command_type,target_entity_type,target_entity_id,command_payload,executed_at) SELECT gen_random_uuid(),'a3333333-3333-3333-3333-333333333333'::UUID,(SELECT user_id FROM users WHERE email='admin@nexo.edu' LIMIT 1),CASE WHEN i%3=0 THEN 'STUDENT_UPDATE' WHEN i%3=1 THEN 'SEND_NOTIFICATION' ELSE 'GENERATE_REPORT' END,CASE WHEN i%3=0 THEN 'student' WHEN i%3=1 THEN 'guardian' ELSE 'report' END,gen_random_uuid(),jsonb_build_object('action','test','index',i),NOW()-(random()*INTERVAL'3 days') FROM generate_series(1,15) i;

INSERT INTO security_incidents(incident_id,school_id,related_student_id,related_user_id,incident_type,severity_level,description,detected_at,resolved) SELECT gen_random_uuid(),'a3333333-3333-3333-3333-333333333333'::UUID,(SELECT student_id FROM students ORDER BY random() LIMIT 1),(SELECT user_id FROM users WHERE email='admin@nexo.edu' LIMIT 1),CASE WHEN random()<0.25 THEN 'UNAUTHORIZED_ACCESS' WHEN random()<0.5 THEN 'DATA_LEAK' WHEN random()<0.75 THEN 'PHYSICAL_THREAT' ELSE 'SUSPICIOUS_BEHAVIOR' END,CASE WHEN random()<0.33 THEN 'LOW' WHEN random()<0.66 THEN 'MEDIUM' ELSE 'HIGH' END,'Incidente simulado',NOW()-(random()*INTERVAL'7 days'),CASE WHEN random()<0.7 THEN TRUE ELSE FALSE END FROM generate_series(1,10) i;

INSERT INTO global_audit_logs(log_id,school_id,performed_by_user_id,action_type,entity_type,entity_id,action_details,ip_address,created_at) SELECT gen_random_uuid(),'a3333333-3333-3333-3333-333333333333'::UUID,(SELECT user_id FROM users WHERE email='admin@nexo.edu' LIMIT 1),CASE WHEN random()<0.25 THEN 'LOGIN' WHEN random()<0.5 THEN 'LOGOUT' WHEN random()<0.75 THEN 'DATA_ACCESS' ELSE 'CONFIG_CHANGE' END,CASE WHEN random()<0.33 THEN 'user' WHEN random()<0.66 THEN 'student' ELSE 'system' END,gen_random_uuid(),jsonb_build_object('event','audit_test'),('192.168.1.'||(10+(i%245))::TEXT)::INET,NOW()-(random()*INTERVAL'7 days') FROM generate_series(1,30) i;

INSERT INTO role_permissions(role_permission_id,role_id,permission_id) SELECT gen_random_uuid(),r.role_id,p.permission_id FROM roles r CROSS JOIN permissions p WHERE r.role_name IN ('SUPER_RECTOR','RECTOR') ON CONFLICT DO NOTHING;

INSERT INTO student_record_audit(audit_id,school_id,student_id,performed_by_user_id,action_type,previous_data,new_data,performed_at) SELECT gen_random_uuid(),'a3333333-3333-3333-3333-333333333333'::UUID,s.student_id,(SELECT user_id FROM users WHERE email='admin@nexo.edu' LIMIT 1),CASE WHEN random()<0.5 THEN 'UPDATE' ELSE 'DELETE' END,jsonb_build_object('old','data'),jsonb_build_object('new','data'),NOW()-(random()*INTERVAL'30 days') FROM students s WHERE s.school_id='a3333333-3333-3333-3333-333333333333'::UUID ORDER BY random() LIMIT 20;

INSERT INTO staff_records(staff_record_id,school_id,user_id,hired_at,position_name,active) SELECT gen_random_uuid(),'a3333333-3333-3333-3333-333333333333'::UUID,u.user_id,'2020-01-01','Docente de Aula',TRUE FROM users u JOIN roles r ON r.role_id=u.role_id WHERE r.role_name='DOCENTE' LIMIT 8;

INSERT INTO schedules(schedule_id,group_id,subject_id,classroom_id,teacher_user_id,day_of_week,block_number,start_time,end_time) SELECT gen_random_uuid(),ag.group_id,s.subject_id,c.classroom_id,(SELECT user_id FROM users WHERE email='admin@nexo.edu' LIMIT 1),1+(i%5)::INT,i,('08:00'::TIME+((i%6)::TEXT||' hours')::INTERVAL),('09:30'::TIME+((i%6)::TEXT||' hours')::INTERVAL) FROM academic_groups ag CROSS JOIN subjects s CROSS JOIN classrooms c, generate_series(1,15) i WHERE ag.school_id='a3333333-3333-3333-3333-333333333333'::UUID LIMIT 30;

INSERT INTO class_exit_authorizations(authorization_id,school_id,student_id,authorized_by_user_id,schedule_id,authorization_reason,exit_time,return_time) SELECT gen_random_uuid(),'a3333333-3333-3333-3333-333333333333'::UUID,s.student_id,(SELECT user_id FROM users WHERE email='admin@nexo.edu' LIMIT 1),(SELECT schedule_id FROM schedules WHERE school_id='a3333333-3333-3333-3333-333333333333'::UUID ORDER BY random() LIMIT 1),'Salida anticipada',CURRENT_DATE+CURRENT_TIME,CURRENT_DATE+CURRENT_TIME+INTERVAL'2 hours' FROM students s WHERE s.school_id='a3333333-3333-3333-3333-333333333333'::UUID ORDER BY random() LIMIT 10;

INSERT INTO school_exit_authorizations(authorization_id,school_id,student_id,authorized_by_user_id,authorization_reason,exit_time,expected_return_time,status) SELECT gen_random_uuid(),'a3333333-3333-3333-3333-333333333333'::UUID,s.student_id,(SELECT user_id FROM users WHERE email='admin@nexo.edu' LIMIT 1),'Salida pedagógica',CURRENT_DATE+CURRENT_TIME,CURRENT_DATE+CURRENT_TIME+INTERVAL'4 hours','APPROVED' FROM students s WHERE s.school_id='a3333333-3333-3333-3333-333333333333'::UUID ORDER BY random() LIMIT 5;

INSERT INTO pedagogical_trip_authorizations(authorization_id,school_id,student_id,authorized_by_user_id,destination,departure_time,return_time,purpose) SELECT gen_random_uuid(),'a3333333-3333-3333-3333-333333333333'::UUID,s.student_id,(SELECT user_id FROM users WHERE email='admin@nexo.edu' LIMIT 1),'Museo de Bogotá',CURRENT_DATE+CURRENT_TIME,CURRENT_DATE+CURRENT_TIME+INTERVAL'6 hours','Visita educativa' FROM students s WHERE s.school_id='a3333333-3333-3333-3333-333333333333'::UUID ORDER BY random() LIMIT 5;

INSERT INTO internal_messages(message_id,school_id,sender_user_id,receiver_user_id,subject,message_content,sent_at) SELECT gen_random_uuid(),'a3333333-3333-3333-3333-333333333333'::UUID,(SELECT user_id FROM users WHERE email='admin@nexo.edu' LIMIT 1),u.user_id,'Asunto '||i,'Mensaje de prueba número '||i,NOW()-(random()*INTERVAL'3 days') FROM users u, generate_series(1,5) i WHERE u.school_id='a3333333-3333-3333-3333-333333333333'::UUID LIMIT 15;

INSERT INTO report_exports(report_export_id,school_id,generated_by_user_id,report_type,file_format,generated_at) SELECT gen_random_uuid(),'a3333333-3333-3333-3333-333333333333'::UUID,(SELECT user_id FROM users WHERE email='admin@nexo.edu' LIMIT 1),CASE WHEN i%3=0 THEN 'ATTENDANCE' WHEN i%3=1 THEN 'BEHAVIOR' ELSE 'BIOMETRIC' END,'PDF',NOW()-(random()*INTERVAL'7 days') FROM generate_series(1,10) i;

INSERT INTO twilio_message_types(type_code,description) VALUES('CITATION','Citación a acudiente'),('NOTIFICATION','Notificación general'),('ABSENCE_ALERT','Alerta de inasistencia'),('LATE_ALERT','Alerta de llegada tardía'),('EMERGENCY','Emergencia') ON CONFLICT DO NOTHING;

INSERT INTO user_sessions(session_id,user_id,refresh_token_hash,ip_address,user_agent,expires_at,revoked) SELECT gen_random_uuid(),u.user_id,md5(u.user_id::TEXT||NOW()::TEXT),('192.168.1.'||(10+(i%245))::TEXT)::INET,'Chrome/Windows',NOW()+INTERVAL'24 hours',FALSE FROM users u, generate_series(1,20) i WHERE u.school_id='a3333333-3333-3333-3333-333333333333'::UUID LIMIT 20;

INSERT INTO student_behavior_metrics(school_id,student_id,calculated_at,late_count,absence_count,total_events,risk_score,risk_level,calculation_window_days) SELECT 'a3333333-3333-3333-3333-333333333333'::UUID,s.student_id,NOW()-(random()*INTERVAL'7 days'),(random()*5)::INT,(random()*3)::INT,20+(random()*80)::INT,LEAST(100.00,(random()*100)),CASE WHEN random()<0.25 THEN 'CRITICAL' WHEN random()<0.5 THEN 'HIGH' WHEN random()<0.75 THEN 'MEDIUM' ELSE 'LOW' END,30 FROM students s WHERE s.school_id='a3333333-3333-3333-3333-333333333333'::UUID ON CONFLICT DO NOTHING;

DO $$
DECLARE i INT; etypes TEXT[]:=ARRAY['INGRESO','SALIDA_ALMUERZO','REGRESO_ALMUERZO','SALIDA'];
eresults TEXT[]:=ARRAY['SUCCESS','SUCCESS','SUCCESS','LATE','ABSENT']; sids UUID[]; dids UUID[];
BEGIN SELECT array_agg(student_id::UUID) INTO sids FROM students WHERE school_id='a3333333-3333-3333-3333-333333333333'::UUID;
SELECT array_agg(device_id::UUID) INTO dids FROM edge_devices WHERE school_id='a3333333-3333-3333-3333-333333333333'::UUID;
FOR i IN 1..300 LOOP
INSERT INTO biometric_events(event_id,school_id,student_id,device_id,event_type,event_result,confidence_score,event_timestamp)
VALUES(gen_random_uuid(),'a3333333-3333-3333-3333-333333333333'::UUID,sids[1+((i*7)%array_length(sids,1))],dids[1+((i*3)%array_length(dids,1))],etypes[1+((i+1)%4)],eresults[1+(i%5)],85.0+(random()*14.9),'2026-06-01'::DATE+((i%30)||' days')::INTERVAL+((7+(i%10))::TEXT||':'||(15+(i%45))::TEXT||':00')::TIME);
END LOOP;
FOR i IN 1..300 LOOP
INSERT INTO biometric_events(event_id,school_id,student_id,device_id,event_type,event_result,confidence_score,event_timestamp)
VALUES(gen_random_uuid(),'a3333333-3333-3333-3333-333333333333'::UUID,sids[1+((i*7)%array_length(sids,1))],dids[1+((i*3)%array_length(dids,1))],etypes[1+((i+1)%4)],eresults[1+(i%5)],85.0+(random()*14.9),'2026-07-01'::DATE+((i%31)||' days')::INTERVAL+((7+(i%10))::TEXT||':'||(15+(i%45))::TEXT||':00')::TIME);
END LOOP;
FOR i IN 1..300 LOOP
INSERT INTO biometric_events(event_id,school_id,student_id,device_id,event_type,event_result,confidence_score,event_timestamp)
VALUES(gen_random_uuid(),'a3333333-3333-3333-3333-333333333333'::UUID,sids[1+((i*7)%array_length(sids,1))],dids[1+((i*3)%array_length(dids,1))],etypes[1+((i+1)%4)],eresults[1+(i%5)],85.0+(random()*14.9),'2026-08-01'::DATE+((i%31)||' days')::INTERVAL+((7+(i%10))::TEXT||':'||(15+(i%45))::TEXT||':00')::TIME);
END LOOP;
END $$;
