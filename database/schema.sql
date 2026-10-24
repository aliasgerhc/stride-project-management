CREATE DATABASE IF NOT EXISTS stride CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE stride;

CREATE TABLE IF NOT EXISTS users (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(120) NOT NULL,
  email VARCHAR(190) NOT NULL UNIQUE,
  password_hash VARCHAR(255) NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);
CREATE TABLE IF NOT EXISTS workspaces (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(140) NOT NULL,
  owner_name VARCHAR(120) NOT NULL DEFAULT 'Alex Lee',
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);
CREATE TABLE IF NOT EXISTS workspace_members (
  workspace_id BIGINT UNSIGNED NOT NULL,
  user_id BIGINT UNSIGNED NOT NULL,
  role ENUM('Owner','Admin','Member','Viewer') NOT NULL DEFAULT 'Member',
  joined_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (workspace_id,user_id),
  FOREIGN KEY (workspace_id) REFERENCES workspaces(id) ON DELETE CASCADE,
  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
);
CREATE TABLE IF NOT EXISTS projects (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  workspace_id BIGINT UNSIGNED NOT NULL,
  name VARCHAR(180) NOT NULL,
  project_key VARCHAR(12) NOT NULL,
  description TEXT,
  category VARCHAR(60) NOT NULL DEFAULT 'General',
  color CHAR(7) NOT NULL DEFAULT '#8976f5',
  status ENUM('Planning','In progress','Completed','On hold') NOT NULL DEFAULT 'Planning',
  due_date DATE NULL,
  lead_id BIGINT UNSIGNED NULL,
  is_starred TINYINT(1) NOT NULL DEFAULT 0,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  FOREIGN KEY (workspace_id) REFERENCES workspaces(id) ON DELETE CASCADE,
  FOREIGN KEY (lead_id) REFERENCES users(id) ON DELETE SET NULL,
  INDEX idx_projects_workspace (workspace_id)
);
CREATE TABLE IF NOT EXISTS tasks (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  project_id BIGINT UNSIGNED NOT NULL,
  title VARCHAR(220) NOT NULL,
  description TEXT,
  assignee_id BIGINT UNSIGNED NULL,
  due_date DATE NULL,
  priority ENUM('Low','Medium','High','Urgent') NOT NULL DEFAULT 'Medium',
  status ENUM('To do','In progress','In review','Done') NOT NULL DEFAULT 'To do',
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  FOREIGN KEY (project_id) REFERENCES projects(id) ON DELETE CASCADE,
  FOREIGN KEY (assignee_id) REFERENCES users(id) ON DELETE SET NULL,
  INDEX idx_tasks_project_status (project_id,status),
  INDEX idx_tasks_assignee (assignee_id)
);
CREATE TABLE IF NOT EXISTS activity_log (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  workspace_id BIGINT UNSIGNED NOT NULL,
  user_id BIGINT UNSIGNED NULL,
  action VARCHAR(80) NOT NULL,
  entity_type VARCHAR(40) NOT NULL,
  entity_id BIGINT UNSIGNED NULL,
  details JSON NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (workspace_id) REFERENCES workspaces(id) ON DELETE CASCADE,
  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL,
  INDEX idx_activity_workspace_created (workspace_id,created_at)
);

INSERT INTO users (name,email) VALUES
('Alex Lee','alex@northstar.studio'),('Morgan Kim','morgan@northstar.studio'),('Jamie Smith','jamie@northstar.studio'),('Devon Wu','devon@northstar.studio'),('Taylor Reed','taylor@northstar.studio')
ON DUPLICATE KEY UPDATE name=VALUES(name);
INSERT INTO workspaces (name,owner_name) SELECT 'Northstar Studio','Alex Lee' WHERE NOT EXISTS (SELECT 1 FROM workspaces WHERE name='Northstar Studio');
INSERT INTO workspace_members (workspace_id,user_id,role)
SELECT w.id,u.id,CASE WHEN u.name='Alex Lee' THEN 'Owner' ELSE 'Member' END FROM workspaces w CROSS JOIN users u WHERE w.name='Northstar Studio'
ON DUPLICATE KEY UPDATE role=VALUES(role);
INSERT INTO projects (workspace_id,name,project_key,description,color,status,due_date,lead_id)
SELECT w.id,p.name,p.project_key,p.description,p.color,p.status,p.due_date,u.id FROM workspaces w JOIN users u ON u.email='alex@northstar.studio' CROSS JOIN (SELECT 'Website redesign' name,'WEB' project_key,'A fresh digital home for the next chapter.' description,'#8976f5' color,'In progress' status,'2026-10-30' due_date UNION ALL SELECT 'Mobile app launch','MOB','Take the product experience wherever you go.','#f2a77c','In progress','2026-11-06' UNION ALL SELECT 'Q3 content calendar','CON','Make every story count this quarter.','#59b8a5','Planning','2026-11-02' UNION ALL SELECT 'Customer research','RES','Get closer to the people who use our product.','#e884a4','In progress','2026-10-29') p WHERE w.name='Northstar Studio' AND NOT EXISTS (SELECT 1 FROM projects existing WHERE existing.workspace_id=w.id AND existing.name=p.name);
INSERT INTO tasks (project_id,title,assignee_id,due_date,priority,status)
SELECT p.id,t.title,u.id,t.due_date,t.priority,t.status FROM
(SELECT 'Website redesign' project_name,'Finalize homepage wireframes' title,'alex@northstar.studio' email,'2026-10-26' due_date,'High' priority,'In progress' status UNION ALL
 SELECT 'Mobile app launch','Review onboarding flow','jamie@northstar.studio','2026-10-26','Medium','In progress' UNION ALL
 SELECT 'Customer research','Share interview findings','taylor@northstar.studio','2026-10-27','High','To do' UNION ALL
 SELECT 'Q3 content calendar','Prepare campaign brief','morgan@northstar.studio','2026-10-28','Low','To do') t
JOIN projects p ON p.name=t.project_name JOIN workspaces w ON w.id=p.workspace_id AND w.name='Northstar Studio' JOIN users u ON u.email=t.email
WHERE NOT EXISTS (SELECT 1 FROM tasks existing WHERE existing.project_id=p.id AND existing.title=t.title);
