"""
corpus_colombia.py — Corpus informal de cultura general colombiana.

Registra intents en el CORPUS global de corpus.py:
  colombia_capital, colombia_department, colombia_president, colombia_history,
  colombia_geography, colombia_culture, colombia_fun_fact, foreign_culture,
  math_operation.
El NER enmascara entidades → el modelo aprende estructura, no nombres.
"""

import random
import unicodedata

from corpus import CORPUS, intent, _augment, _expand, PRE, SUF
from kb_colombia import DEPARTMENTS, PRESIDENTS, CITIES

random.seed(42)

_DEPTS = list(DEPARTMENTS.keys())
_PREZ  = list(PRESIDENTS.keys())
_CITY  = list(CITIES.keys())


# ══ Cultura general colombiana ════════════════════════════════════════════════
@intent('colombia_capital')
def _():
    base = [
        "cual es la capital de {dept}", "capital de {dept}", "dime la capital de {dept}",
        "me dices la capital de {dept}", "sabes cual es la capital de {dept}",
        "capital departamental de {dept}", "que ciudad es capital de {dept}",
        "capital del {dept}", "capital de {dept} colombia",
        "cual es la capital del departamento de {dept}",
    ]
    return _augment(_expand(base, dept=_DEPTS))


@intent('colombia_department')
def _():
    base = [
        "cuantos departamentos tiene colombia", "departamentos de colombia",
        "lista de departamentos", "que departamentos hay", "dime los departamentos",
        "en que departamento queda {city}", "a que departamento pertenece {city}",
        "que departamento es {city}", "departamento de {city}",
        "cuales son las regiones de colombia", "regiones naturales de colombia",
        "cuantas regiones tiene colombia",
    ]
    return _augment(_expand(base, city=_CITY))


@intent('colombia_president')
def _():
    base = [
        "quien fue {prez}", "quien es {prez}", "hablame de {prez}",
        "biografia de {prez}", "que hizo {prez}", "presidente {prez}",
        "cuentame sobre {prez}", "que sabes de {prez}",
        "quien fue el presidente {prez}", "presidente de colombia {prez}",
        # preguntas generales
        "quien es el presidente de colombia", "presidente actual",
        "quien gobierna colombia", "presidente de colombia",
        "lista de presidentes", "presidentes de colombia",
        "primer presidente de colombia", "presidentes que ha tenido colombia",
        "mejor presidente de colombia", "presidente que hizo la constitucion",
    ]
    return _augment(_expand(base, prez=_PREZ))


@intent('colombia_history')
def _():
    return _augment([
        "cuando fue la independencia de colombia", "independencia de colombia",
        "batalla de boyaca", "que paso el 20 de julio de 1810",
        "que fue la gran colombia", "cuando se separo panama",
        "que fue el bogotazo", "que fue la violencia",
        "frente nacional", "constitucion de 1991", "constitucion actual",
        "acuerdo de paz", "firma de la paz", "guerra de los mil dias",
        "historia de colombia", "resumen de la historia",
        "quien descubrio colombia", "epoca colonial", "la colonia",
        "el dorado", "leyenda del dorado", "ritual del dorado",
        "guatavita", "laguna de guatavita", "ritos muiscas",
        "quien fue jorge eliecer gaitan", "gaitan", "bogotazo gaitan",
        "proceso 8000", "palacio de justicia", "toma del palacio de justicia",
        "m-19", "farc", "que son las farc", "conflicto armado",
        "colonizacion de america", "santa marta primera ciudad",
    ])


@intent('colombia_geography')
def _():
    base = [
        "rio mas largo de colombia", "rios de colombia", "rio principal",
        "montaña mas alta de colombia", "pico mas alto", "sierra nevada",
        "que oceanos tiene colombia", "costas colombianas",
        "regiones naturales", "cuantas regiones", "region de colombia",
        "cordilleras de colombia", "tres cordilleras", "los andes",
        "amazonia colombiana", "orinoquia", "llanos orientales",
        "paramos de colombia", "el paramo", "desierto de la guajira",
        "tatacoa", "islas de colombia", "san andres", "archipielago",
        "donde queda {dept}", "ubicacion de {dept}", "en que region esta {dept}",
        "altura de bogota", "a cuantos metros esta bogota",
    ]
    return _augment(_expand(base, dept=_DEPTS))


@intent('colombia_culture')
def _():
    return _augment([
        "que es el vallenato", "vallenato", "musica de colombia",
        "que es la cumbia", "la cumbia", "ritmos colombianos",
        "carnaval de barranquilla", "feria de las flores",
        "carnaval de negros y blancos", "festivales de colombia",
        "quien fue gabriel garcia marquez", "gabo", "cien años de soledad",
        "realismo magico", "premio nobel colombiano",
        "fernando botero", "botero", "esculturas de botero",
        "que es la bandeja paisa", "comida colombiana", "platos tipicos",
        "ajiaco", "comida tipica de bogota", "arepas", "empanadas",
        "cafe colombiano", "juan valdez", "eje cafetero",
        "esmeraldas colombianas", "sombrero vueltiao", "tejo",
        "deporte nacional", "san agustin", "parque arqueologico",
        "shakira", "james rodriguez", "egan bernal", "nairo quintana",
        "ciclistas colombianos", "futbolistas colombianos",
        "folclor colombiano", "danza tipica", "musica tipica",
        "marimba", "currulao", "mapale", "champeta", "porro",
        "san basilio de palenque", "palenque", "indigenas de colombia",
        "lenguas indigenas", "wayuu", "muiscas", "embera", "tayrona",
        "ruana", "artesanias", "mochilas wayuu",
    ])


@intent('colombia_fun_fact')
def _():
    return _augment([
        "un dato curioso de colombia", "dato curioso colombiano",
        "cuentame algo curioso de colombia", "dato curioso del pais",
        "sabias que", "dato interesante de colombia", "algo curioso de colombia",
        "dato historico curioso", "curiosidad colombiana", "sorprendeme con colombia",
        "dato que no sepa de colombia", "algo que no sepa del pais",
        "un dato curioso", "dime algo curioso", "sorprendeme", "impresioname",
    ])


# ══ Fuera del dominio patrio — dispara el speech de identidad nacional ════════
@intent('foreign_culture')
def _():
    return _augment([
        "capital de francia", "capital de japon", "capital de alemania",
        "capital de italia", "capital de españa", "capital de estados unidos",
        "capital de mexico", "capital de argentina", "capital de brasil",
        "capital de chile", "capital de peru", "capital de venezuela",
        "capital de inglaterra", "capital de portugal", "capital de canada",
        "cual es la capital de {country}", "dime la capital de {country}",
        "historia de roma", "historia de grecia", "imperio romano",
        "revolucion francesa", "segunda guerra mundial", "primera guerra mundial",
        "guerra fria", "caida del muro de berlin", "holocausto",
        "napoleon", "julio cesar", "cleopatra", "hitler",
        "quien fue napoleon", "quien fue hitler", "quien fue cleopatra",
        "piramides de egipto", "torre eiffel", "estatua de la libertad",
        "muralla china", "machu picchu", "coliseo romano",
        "presidente de estados unidos", "presidente de mexico",
        "presidente de argentina", "presidente de españa", "rey de españa",
        "monarquia inglesa", "familia real", "vaticano", "el papa",
        "geografia de europa", "mapa de europa", "paises de asia",
        "africa", "oceania", "antartida", "artico", "polo norte",
        "luna", "marte", "espacio exterior", "sistema solar",
        "montaña mas alta del mundo", "everest", "rio mas largo del mundo",
        "amazonas de brasil", "nilo", "oceano pacifico",
        "cuantos paises hay en el mundo", "continentes", "cuantos continentes",
        "moneda de japon", "euro", "dolar", "peso argentino",
        "bandera de mexico", "himno de argentina", "idioma de brasil",
        # temas ajenos a la patria → también disparan el speech
        "horoscopo de hoy", "mi horoscopo", "signo zodiacal", "aries hoy",
        "receta de arepas", "receta de pizza", "como hacer pizza",
        "receta de cocina", "como cocinar pasta", "receta de brownies",
        "resultado de la champions", "quien gano la champions",
        "resultado del partido de ayer", "liga española", "premier league",
        "medicina para la gripe", "que tomo para el dolor", "remedio casero",
        "sintomas de gripe", "que es la diabetes", "vitaminas",
        "bolsa de valores", "precio del dolar", "bitcoin", "acciones",
        "inversiones", "criptomonedas", "wall street",
        "noticias internacionales", "guerra en ucrania", "conflicto en medio oriente",
        "programacion en python", "javascript", "lenguaje de programacion",
        "ingles", "como decir hola en ingles", "traduce al frances",
        "cuando es halloween", "navidad en estados unidos", "thanksgiving",
    ])


# ══ Matemáticas → calculadora (el modelo detecta; la API calcula) ════════════
@intent('math_operation')
def _():
    base = [
        # aritmética literal
        "cuanto es {a} + {b}", "cuanto es {a} - {b}", "cuanto es {a} * {b}",
        "cuanto es {a} / {b}", "cuanto es {a} x {b}", "cuanto da {a} + {b}",
        "suma {a} y {b}", "resta {a} menos {b}", "multiplica {a} por {b}",
        "divide {a} entre {b}", "{a} entre {b}", "{a} por {b}",
        "{a} mas {b}", "{a} menos {b}", "{a} sobre {b}",
        # potencia / raíz / porcentaje / factorial
        "{a} elevado a la {b}", "{a} a la {b}", "{a} al cuadrado",
        "{a} al cubo", "raiz cuadrada de {a}", "raiz de {a}",
        "raiz cubica de {a}", "raiz {b} de {a}", "{a} por ciento de {b}",
        "el {a}% de {b}", "{a} porciento de {b}", "factorial de {a}", "{a}!",
        # trigonometría y logaritmos
        "seno de {a}", "coseno de {a}", "tangente de {a}", "seno de {a} grados",
        "logaritmo de {a}", "logaritmo base {b} de {a}", "log de {a}",
        "ln de {a}", "logaritmo natural de {a}",
        # operaciones compuestas / expresiones
        "resuelve {expr}", "cuanto es {expr}", "calcula {expr}",
        "operacion {expr}", "resultado de {expr}", "evalua {expr}",
        # mcm/mcd/regla de tres/áreas/pitágoras/cuadrática
        "mcm de {a} y {b}", "mcd de {a} y {b}", "minimo comun multiplo de {a} y {b}",
        "maximo comun divisor de {a} y {b}", "regla de tres si {a} valen {b} cuanto valen {c}",
        "area de un circulo de radio {a}", "area del triangulo base {a} altura {b}",
        "area del cuadrado de lado {a}", "area del rectangulo {a} por {b}",
        "hipotenusa de catetos {a} y {b}", "pitagoras {a} y {b}",
        "ecuacion cuadratica {a} {b} {c}", "resuelve {a}x2 + {b}x + {c} = 0",
        "raices de {a}x^2 + {b}x + {c}", "formula cuadratica {a} {b} {c}",
        # redondeo / valor absoluto / conversión
        "valor absoluto de {a}", "redondea {a}", "convierte {a} km a metros",
        "cuantos minutos hay en {a} horas", "cuantos segundos en {a} minutos",
        "{a} grados a radianes", "{a} radianes a grados",
    ]
    nums = [str(n) for n in [2,3,4,5,6,7,8,9,10,12,15,16,20,24,25,30,36,40,45,
                             48,50,60,64,75,80,90,96,100,120,125,144,150,180,
                             200,225,250,256,300,360,400,500,512,625,720,1000,
                             '3.14','0.5','2.5','7.5','1.5','12.5','37.5']]
    exprs = ['(3+5)*2','2*(10-3)','(100-20)/4','3^2+4^2','sqrt(144)+5',
             '2*(15+7)/11','(8+4)*(6-2)','10/3+7','(25*4)-50','(2^10)/8',
             '15*3+27/9','(144/12)^2','sqrt(81)*3','(7+3)*(8-2)/5']
    return _augment(_expand(base, a=nums, b=nums, c=nums, expr=exprs))
