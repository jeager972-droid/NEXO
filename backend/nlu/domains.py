"""
domains.py — Mapa intent → dominio (formal | informal).

Router nivel 1 decide formal vs informal; el submodelo nivel 2 clasifica
el intent dentro del dominio. La misión crítica SIEMPRE gana: cualquier
señal de datos institucionales fuerza el dominio formal.
"""

FORMAL = {
    'day_summary','attendance_today','late_today','count_events','list_events',
    'student_field','student_summary','group_summary','risk_students',
    'trackings','permissions','citations','devices_status',
    'notifications_unread','audit_query','students_count','groups_list',
    'teachers_list','schedule_info','export_data','derive_action',
    'about_me','help','capabilities','security_probe',
    'random_student','staff_lookup','start_operation','count_present',
    'count_trackings','students_in_group',
    'top_offenders','pending_returns','sos_alerts','biometric_spam',
    'group_student_count','birthdays_today','my_activity','failed_messages',
    'risk_config','attendance_ranking','session_summary','pending_tasks',
    'whatsapp_status',
}

INFORMAL = {
    'greeting','greeting_time','wellbeing','wellbeing_reply','joke','fun_fact',
    'about_nexus','name_meaning','creator','age','thanks','goodbye','yes','no',
    'apology','compliment','insult','bored','love','human_check','do_for_me',
    'emotion_sad','weather','news_sports','food_music','meaning_life',
    'confused','repeat','insult_back','sing','dance','story','motivation',
    'time','date','out_of_scope',
    'colombia_capital','colombia_department','colombia_president',
    'colombia_history','colombia_geography','colombia_culture',
    'colombia_fun_fact','foreign_culture','math_operation',
}

def domain_of(intent: str) -> str:
    return 'formal' if intent in FORMAL else 'informal'
