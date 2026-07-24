UPDATE users
SET work_shift = 'tarde'
WHERE work_shift IS NULL
  AND role_id = (SELECT role_id FROM roles WHERE role_name = 'TEACHER');
