<?php

use App\Mago\ClassRules;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitor\CloningVisitor;
use PhpParser\ParserFactory;
use PhpParser\PrettyPrinter\Standard;

it('applies class rules to a cloned tree without changing the original', function (
    array $rules,
    string $input,
    string $expected,
) {
    $parser = new ParserFactory()->createForNewestSupportedVersion();
    $printer = new Standard;
    $original = $parser->parse('<?php '.$input);
    $tokens = $parser->getTokens();
    $before = $printer->prettyPrintFile($original);
    $cloned = new NodeTraverser(new CloningVisitor)->traverse($original);
    $result = new NodeTraverser(new ClassRules($rules))->traverse($cloned);

    expect($printer->prettyPrintFile($result))->toBe($printer->prettyPrintFile($parser->parse('<?php '.$expected)));
    $preserved = $printer->printFormatPreserving($result, $original, $tokens);
    expect($printer->prettyPrintFile($parser->parse($preserved)))->toBe($printer->prettyPrintFile($result));
    expect($printer->prettyPrintFile($original))->toBe($before);
})->with([
    'disabled rules' => [
        ['final_class' => false, 'self_accessor' => false],
        'class A { function run() { return new A; } }',
        'class A { function run() { return new A; } }',
    ],
    'named classes only' => [
        ['final_class' => true],
        'class A {} abstract class B {} trait C {} interface D {} enum E {} $x = new class {};',
        'final class A {} abstract class B {} trait C {} interface D {} enum E {} $x = new class {};',
    ],
    'exclusions preserve attributes and docs' => [
        ['final_class' => true],
        '/** @ORM\\Entity */ class A {} #[Entity] class B {} /** @FINAL */ class C {} #[Mapping\\Entity] class D {}',
        '/** @ORM\\Entity */ class A {} #[Entity] class B {} /** @FINAL */ class C {} #[Mapping\\Entity] class D {}',
    ],
    'internal is opt in' => [
        ['final_internal_class' => true],
        '/** @INTERNAL */ class A {} #[Internal] class B {} class C {} /** @internal @Document */ class D {}',
        '/** @INTERNAL */ final class A {} #[Internal] final class B {} class C {} /** @internal @Document */ class D {}',
    ],
    'internal configured tags' => [
        ['final_internal_class' => ['include' => ['Package'], 'exclude' => ['Keep']]],
        '#[Package] class A {} #[Package, Keep] class B {} /** @internal */ class C {}',
        '#[Package] final class A {} #[Package, Keep] class B {} /** @internal */ class C {}',
    ],
    'visibility before abstract finals' => [
        ['visibility_required' => true, 'final_public_method_for_abstract_class' => true],
        'abstract class A { const X = 1; function run() {} public function __serialize() {} public function __construct() {} abstract public function pending(); protected function keep() {} }',
        'abstract class A { public const X = 1; final public function run() {} final public function __serialize() {} public function __construct() {} abstract public function pending(); protected function keep() {} }',
    ],
    'explicit public only without visibility' => [
        ['final_public_method_for_abstract_class' => true],
        'abstract class A { function run() {} public function yes() {} public function __invoke() {} }',
        'abstract class A { function run() {} final public function yes() {} public function __invoke() {} }',
    ],
    'visibility selected elements alias' => [
        ['modifier_keywords' => ['elements' => ['const']]],
        'class A { const X = 1; function run() {} }',
        'class A { public const X = 1; function run() {} }',
    ],
    'private eligible members and promotion' => [
        ['protected_to_private' => true],
        'final class A implements I { protected const X = 1; final protected const Y = 2; protected $x; protected function run() {} public function __construct(protected int $p) {} }',
        'final class A implements I { private const X = 1; final protected const Y = 2; private $x; private function run() {} public function __construct(private int $p) {} }',
    ],
    'private skips inheritance traits and anonymous' => [
        ['protected_to_private' => true],
        'final class A extends B { protected $x; } final class C { use T; protected $x; } class D { protected $x; } $x = new class { protected $x; }; enum E { protected function run() {} }',
        'final class A extends B { protected $x; } final class C { use T; protected $x; } class D { protected $x; } $x = new class { protected $x; }; enum E { private function run() {} }',
    ],
    'self names signature and expressions not properties or lambdas' => [
        ['self_accessor' => true],
        'namespace N; class A { public A $p; function run(?A $a): \\N\\A { A::run(); new A; $a instanceof A; new namespace\\A; new Alias; $f = function (A $a): A { return new A; }; $g = fn (A $a): A => new A; return new \\N\\A; } }',
        'namespace N; class A { public A $p; function run(?self $a): self { self::run(); new self; $a instanceof self; new namespace\\A; new Alias; $f = function (A $a): A { return new A; }; $g = fn (A $a): A => new A; return new self; } }',
    ],
    'self interface but not traits enums nested anonymous' => [
        ['self_accessor' => true],
        'interface A { function run(A $a): A; } trait B { function run() { return new B; } } enum C { case One; function run() { return C::One; } } class D { function run() { return new class { function run() { return new D; } }; } }',
        'interface A { function run(self $a): self; } trait B { function run() { return new B; } } enum C { case One; function run() { return C::One; } } class D { function run() { return new class { function run() { return new D; } }; } }',
    ],
    'static self keeps return and closure changes arrow' => [
        ['self_static_accessor' => true],
        'final class A { function run(): static { new static; $x instanceof static; static::run(); $f = function () { return new static; }; $g = fn () => new static; return new class { function run() { return new static; } }; } } class B { function run() { return new static; } } enum C { function run() { return static::class; } }',
        'final class A { function run(): static { new self; $x instanceof self; self::run(); $f = function () { return new static; }; $g = fn () => new self; return new class { function run() { return new self; } }; } } class B { function run() { return new static; } } enum C { function run() { return self::class; } }',
    ],
    'interface ordering normalized and stable' => [
        ['ordered_interfaces' => true],
        'class A implements Z, B\\C, b\\c, B_C {} interface I extends Z, A {}',
        'class A implements B\\C, b\\c, B_C, Z {} interface I extends A, Z {}',
    ],
    'interface length descending' => [
        ['ordered_interfaces' => ['order' => 'length', 'direction' => 'descend']],
        'enum E implements A, LongName, Mid {}',
        'enum E implements LongName, Mid, A {}',
    ],
    'trait groups preserve adaptations comments and barriers' => [
        ['ordered_traits' => true],
        'class A { /** Z */ use Z; /** A */ use C, A { A::run insteadof C; } public $p; use Y; use B; }',
        'class A { /** A */ use A, C { A::run insteadof C; } /** Z */ use Z; public $p; use B; use Y; }',
    ],
    'default class order stable with member comments' => [
        ['ordered_class_elements' => true],
        'class A { /** Z */ public function z() {} /** A */ public function a() {} protected $x; const X = 1; use T; function __construct() {} }',
        'class A { use T; const X = 1; protected $x; function __construct() {} /** Z */ public function z() {} /** A */ public function a() {} }',
    ],
    'custom static and abstract groups' => [
        [
            'ordered_class_elements' => [
                'order' => [
                    'property_public',
                    'construct',
                    'method_abstract',
                    'method_public_static',
                    'method_public',
                    'method_protected_static',
                    'method_protected',
                ],
                'sort_algorithm' => 'none',
            ],
        ],
        'abstract class A { protected function z() {} public function b() {} public static function c() {} protected static function d() {} abstract public function a(); public static $p; function __construct() {} }',
        'abstract class A { public static $p; function __construct() {} abstract public function a(); public static function c() {} public function b() {} protected static function d() {} protected function z() {} }',
    ],
    'alpha sorting and phpunit lifecycle' => [
        ['ordered_class_elements' => ['sort_algorithm' => 'alpha']],
        'class A { function z() {} function a() {} function tearDown() {} function setUp() {} static function tearDownAfterClass() {} static function setUpBeforeClass() {} }',
        'class A { static function setUpBeforeClass() {} static function tearDownAfterClass() {} function setUp() {} function tearDown() {} function a() {} function z() {} }',
    ],
    'subtype parents resolve before visibility and keep ties stable' => [
        ['ordered_class_elements' => ['order' => ['method_public', 'method', 'property']]],
        'class A { static function z() {} public $p; function a() {} static function b() {} }',
        'class A { function a() {} static function z() {} static function b() {} public $p; }',
    ],
    'exact subtype outranks abstract parent' => [
        [
            'ordered_class_elements' => ['order' => [
                'method_public',
                'method_public_abstract_static',
                'method_abstract',
            ]],
        ],
        'abstract class A { abstract public function a(); abstract public static function b(); public function c() {} }',
        'abstract class A { public function c() {} abstract public static function b(); abstract public function a(); }',
    ],
    'all lifecycle hooks use lifecycle not alpha order' => [
        ['ordered_class_elements' => ['order' => ['phpunit'], 'sort_algorithm' => 'alpha']],
        'class A { function doTearDown() {} function tearDown() {} function assertPostConditions() {} function assertPreConditions() {} function doSetUp() {} function setUp() {} static function doTearDownAfterClass() {} static function tearDownAfterClass() {} static function doSetUpBeforeClass() {} static function setUpBeforeClass() {} }',
        'class A { static function setUpBeforeClass() {} static function doSetUpBeforeClass() {} static function tearDownAfterClass() {} static function doTearDownAfterClass() {} function setUp() {} function doSetUp() {} function assertPreConditions() {} function assertPostConditions() {} function tearDown() {} function doTearDown() {} }',
    ],
    'combined modifiers and references' => [
        [
            'final_class' => true,
            'protected_to_private' => true,
            'visibility_required' => true,
            'self_accessor' => true,
            'self_static_accessor' => true,
        ],
        'class A { protected A $p; function __construct(protected int $p) {} function run(A $a): static { return static::make(new A); } }',
        'final class A { private A $p; public function __construct(private int $p) {} public function run(self $a): static { return self::make(new self); } }',
    ],
    'nested classes inside closures establish their own static scope' => [
        ['self_static_accessor' => true],
        'final class A { function run() { $f = function () { new static; return new class { function run() { return static::class; } }; }; } }',
        'final class A { function run() { $f = function () { new static; return new class { function run() { return self::class; } }; }; } }',
    ],
    'Pinkary complete custom order' => [
        [
            'ordered_class_elements' => [
                'order' => [
                    'use_trait',
                    'case',
                    'constant',
                    'constant_public',
                    'constant_protected',
                    'constant_private',
                    'property_public',
                    'property_protected',
                    'property_private',
                    'construct',
                    'destruct',
                    'magic',
                    'phpunit',
                    'method_abstract',
                    'method_public_static',
                    'method_public',
                    'method_protected_static',
                    'method_protected',
                    'method_private_static',
                    'method_private',
                ],
                'sort_algorithm' => 'none',
            ],
        ],
        'abstract class A { private function last() {} protected static function middle() {} public function run() {} public static function make() {} abstract protected function pending(); public function __invoke() {} function __construct() {} private $p; public static $s; private const PRIVATE_VALUE = 1; public const PUBLIC_VALUE = 2; use T; }',
        'abstract class A { use T; public const PUBLIC_VALUE = 2; private const PRIVATE_VALUE = 1; public static $s; private $p; function __construct() {} public function __invoke() {} abstract protected function pending(); public static function make() {} public function run() {} protected static function middle() {} private function last() {} }',
    ],
]);
