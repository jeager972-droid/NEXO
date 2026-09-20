"""
math_ner.py — NER matemático: extrae la operación y sus variables del texto.

Devuelve un JSON limpio que el PHP calcula exactamente (lib/calculator.php).
El modelo NLU nunca resuelve matemáticas — solo detecta y estructura:

  "cuanto es 15% de 200"      → {concept:'porcentaje', function:'percent', a:15, b:200}
  "raiz cuadrada de 144"      → {concept:'raiz', function:'sqrt', a:144}
  "2 elevado a la 10"         → {concept:'potencia', function:'pow', base:2, exponent:10}
  "resuelve (3+5)*2"          → {concept:'expresion', function:'eval', expr:'(3+5)*2'}
  "seno de 30 grados"         → {concept:'trig', function:'sin', a:30, unit:'deg'}
  "regla de tres si 4 valen 10 cuanto valen 7" → {concept:'regla3', a:4,b:10,c:7}
"""

import re

# número: dígitos o palabras en español (0-99 básicas + centenas)
_NUM_WORDS = {
    'cero':0,'un':1,'uno':1,'una':1,'dos':2,'tres':3,'cuatro':4,'cinco':5,
    'seis':6,'siete':7,'ocho':8,'nueve':9,'diez':10,'once':11,'doce':12,
    'trece':13,'catorce':14,'quince':15,'dieciseis':16,'diecisiete':17,
    'dieciocho':18,'diecinueve':19,'veinte':20,'veintiuno':21,'veintidos':22,
    'veintitres':23,'veinticuatro':24,'veinticinco':25,'treinta':30,
    'cuarenta':40,'cincuenta':50,'sesenta':60,'setenta':70,'ochenta':80,
    'noventa':90,'cien':100,'ciento':100,'doscientos':200,'trescientos':300,
    'cuatrocientos':400,'quinientos':500,'seiscientos':600,'setecientos':700,
    'ochocientos':800,'novecientos':900,'mil':1000,'millon':1000000,
}

_NUM = r'(?:-?\d+(?:[.,]\d+)?|infinito)'
_NUM_RE = r'(' + _NUM + ')'
_EXPR_RE = r'[\d\s+\-*/^().,%]+'


def _to_num(s):
    s = s.strip().lower().replace(',', '.')
    if s in _NUM_WORDS:
        return float(_NUM_WORDS[s])
    try:
        v = float(s)
        return int(v) if v == int(v) else v
    except ValueError:
        return None


def _all_nums(q):
    return [float(n) if '.' in n else int(n)
            for n in re.findall(_NUM_RE, q)]


def extract_math(q: str):
    """q = texto normalizado (sin tildes, sin signos). Devuelve dict o None."""
    # ── valor absoluto / redondeo (antes de la expresión literal) ──
    m = re.search(rf'valor absoluto de\s*{_NUM_RE}', q)
    if m:
        return {'concept': 'absoluto', 'function': 'abs', 'a': _to_num(m.group(1))}
    m = re.search(rf'redondea\s*{_NUM_RE}', q)
    if m:
        return {'concept': 'redondeo', 'function': 'round', 'a': _to_num(m.group(1))}

    # ── expresión literal (tiene operadores) ──
    m = re.search(rf'({_EXPR_RE}[\+\-\*/\^]{_EXPR_RE})', q)
    if m and re.search(r'[\+\-\*/\^]', m.group(1).strip()):
        expr = m.group(1).strip()
        # solo si parece aritmética real: contiene dígito + operador
        if re.search(r'\d', expr):
            return {'concept': 'expresion', 'function': 'eval', 'expr': expr}

    # ── porcentaje ──
    m = re.search(rf'(?:el\s+)?{_NUM_RE}\s*(?:%|por ?ciento|porciento)\s*de\s*{_NUM_RE}', q)
    if m:
        return {'concept': 'porcentaje', 'function': 'percent',
                'a': _to_num(m.group(1)), 'b': _to_num(m.group(2))}

    # ── potencia / raíz ──
    m = re.search(rf'{_NUM_RE}\s*(?:elevado\s*a\s*(?:la\s*)?|a\s*la\s*|al\s*)\s*(cuadrado|cubo|{_NUM})', q)
    if m:
        exp = {'cuadrado': 2, 'cubo': 3}.get(m.group(2), _to_num(m.group(2) or ''))
        if exp:
            return {'concept': 'potencia', 'function': 'pow',
                    'base': _to_num(m.group(1)), 'exponent': exp}
    m = re.search(rf'raiz\s*(cubica|cuadrada|{_NUM})?\s*de\s*{_NUM_RE}', q)
    if m:
        g = m.group(1)
        deg = {'cuadrada': 2, 'cubica': 3}.get(g or 'cuadrada', _to_num(g or '') or 2)
        return {'concept': 'raiz', 'function': 'root',
                'a': _to_num(m.group(2)), 'index': deg}

    # ── trig / log ──
    m = re.search(rf'(seno|coseno|tangente|secante|cosecante|cotangente)\s*de\s*{_NUM_RE}\s*(grados|radianes|rad)?', q)
    if m:
        fn = {'seno':'sin','coseno':'cos','tangente':'tan','secante':'sec',
              'cosecante':'csc','cotangente':'cot'}[m.group(1)]
        return {'concept': 'trigonometria', 'function': fn,
                'a': _to_num(m.group(2)), 'unit': 'rad' if (m.group(3)=='radianes' or m.group(3)=='rad') else 'deg'}
    m = re.search(rf'(?:ln|logaritmo natural)\s*de\s*{_NUM_RE}', q)
    if m:
        return {'concept': 'logaritmo', 'function': 'ln', 'a': _to_num(m.group(1))}
    m = re.search(rf'log(?:aritmo)?(?:\s*base\s*{_NUM_RE})?\s*de\s*{_NUM_RE}', q)
    if m:
        base = _to_num(m.group(1) or '') or 10
        return {'concept': 'logaritmo', 'function': 'log',
                'a': _to_num(m.group(2)), 'base': base}
    m = re.search(rf'factorial\s*de\s*{_NUM_RE}|{_NUM_RE}\s*!', q)
    if m:
        return {'concept': 'factorial', 'function': 'fact', 'a': _to_num(m.group(1) or m.group(2))}

    # ── mcm / mcd / regla de tres ──
    m = re.search(rf'(?:mcm|minimo comun multiplo)\s*de\s*{_NUM_RE}\s*(?:y|,)\s*{_NUM_RE}', q)
    if m:
        return {'concept': 'mcm', 'function': 'lcm', 'a': _to_num(m.group(1)), 'b': _to_num(m.group(2))}
    m = re.search(rf'(?:mcd|maximo comun divisor)\s*de\s*{_NUM_RE}\s*(?:y|,)\s*{_NUM_RE}', q)
    if m:
        return {'concept': 'mcd', 'function': 'gcd', 'a': _to_num(m.group(1)), 'b': _to_num(m.group(2))}
    m = re.search(rf'regla de tres.*?{_NUM_RE}\s*(?:valen|equivalen a|son)\s*{_NUM_RE}.*?{_NUM_RE}', q)
    if m:
        return {'concept': 'regla3', 'function': 'rule3',
                'a': _to_num(m.group(1)), 'b': _to_num(m.group(2)), 'c': _to_num(m.group(3))}

    # ── áreas ──
    m = re.search(rf'area\s*del?\s*(?:un\s*)?circulo.*?radio\s*{_NUM_RE}', q)
    if m:
        return {'concept': 'area', 'function': 'area_circle', 'a': _to_num(m.group(1))}
    m = re.search(rf'area\s*del?\s*(?:un\s*)?triangulo.*?base\s*{_NUM_RE}.*?altura\s*{_NUM_RE}', q)
    if m:
        return {'concept': 'area', 'function': 'area_triangle',
                'a': _to_num(m.group(1)), 'b': _to_num(m.group(2))}
    m = re.search(rf'area\s*del?\s*(?:un\s*)?cuadrado.*?lado\s*{_NUM_RE}', q)
    if m:
        return {'concept': 'area', 'function': 'area_square', 'a': _to_num(m.group(1))}
    m = re.search(rf'area\s*del?\s*(?:un\s*)?rectangulo.*?{_NUM_RE}\s*(?:por|x|,)\s*{_NUM_RE}', q)
    if m:
        return {'concept': 'area', 'function': 'area_rect',
                'a': _to_num(m.group(1)), 'b': _to_num(m.group(2))}

    # ── pitágoras / cuadrática ──
    m = re.search(rf'(?:hipotenusa|pitagoras).*?catetos?\s*{_NUM_RE}\s*(?:y|,)\s*{_NUM_RE}|hipotenusa.*?{_NUM_RE}\s*(?:y|,)\s*{_NUM_RE}', q)
    if m:
        return {'concept': 'pitagoras', 'function': 'hypot',
                'a': _to_num(m.group(1)), 'b': _to_num(m.group(2))}
    m = re.search(rf'(?:cuadratica|x2|x\^2).*?{_NUM_RE}.*?{_NUM_RE}.*?{_NUM_RE}', q)
    if m and 'cuadratica' in q or 'x2' in q or 'x^2' in q:
        vals = _all_nums(q)
        if len(vals) >= 3:
            return {'concept': 'cuadratica', 'function': 'quadratic',
                    'a': vals[0], 'b': vals[1], 'c': vals[2]}

    # ── conversión ──
    m = re.search(rf'{_NUM_RE}\s*(km|kilometros)\s*a\s*(metros?|m)\b', q)
    if m:
        return {'concept': 'conversion', 'function': 'km_to_m', 'a': _to_num(m.group(1))}
    m = re.search(rf'(?:cuantos\s+)?(minutos|segundos|horas)\s*(?:hay\s+)?en\s*{_NUM_RE}\s*(horas|minutos|dias)', q)
    if m:
        return {'concept': 'conversion', 'function': 'time_conv',
                'a': _to_num(m.group(2)), 'from': m.group(3), 'to': m.group(1)}
    m = re.search(rf'{_NUM_RE}\s*grados\s*a\s*radianes|{_NUM_RE}\s*grados', q)
    if m and 'radianes' in q or 'rad' in q:
        return {'concept': 'conversion', 'function': 'deg_to_rad', 'a': _to_num(m.group(1))}
    m = re.search(rf'{_NUM_RE}\s*radianes\s*a\s*grados', q)
    if m:
        return {'concept': 'conversion', 'function': 'rad_to_deg', 'a': _to_num(m.group(1))}

    # ── aritmética de dos operandos (verbos o símbolos) ──
    m = re.search(rf'suma\s*{_NUM_RE}\s*(?:y|mas|\+)\s*{_NUM_RE}|{_NUM_RE}\s*(?:mas|\+)\s*{_NUM_RE}', q)
    if m:
        a = _to_num(m.group(1) or m.group(3)); b = _to_num(m.group(2) or m.group(4))
        return {'concept': 'aritmetica', 'function': 'add', 'a': a, 'b': b}
    m = re.search(rf'resta\s*{_NUM_RE}\s*(?:menos|\-)\s*{_NUM_RE}|{_NUM_RE}\s*(?:menos|\-)\s*{_NUM_RE}', q)
    if m:
        a = _to_num(m.group(1) or m.group(3)); b = _to_num(m.group(2) or m.group(4))
        return {'concept': 'aritmetica', 'function': 'sub', 'a': a, 'b': b}
    m = re.search(rf'multiplica\s*{_NUM_RE}\s*(?:por|x|\*)\s*{_NUM_RE}|{_NUM_RE}\s*(?:por|x|\*)\s*{_NUM_RE}', q)
    if m:
        a = _to_num(m.group(1) or m.group(3)); b = _to_num(m.group(2) or m.group(4))
        return {'concept': 'aritmetica', 'function': 'mul', 'a': a, 'b': b}
    m = re.search(rf'divide\s*{_NUM_RE}\s*(?:entre|/)\s*{_NUM_RE}|{_NUM_RE}\s*(?:entre|/|sobre)\s*{_NUM_RE}', q)
    if m:
        a = _to_num(m.group(1) or m.group(3)); b = _to_num(m.group(2) or m.group(4))
        return {'concept': 'aritmetica', 'function': 'div', 'a': a, 'b': b}

    return None
