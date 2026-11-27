<?php

namespace ApexToolbox\Vendor\Laravel\SerializableClosure;

use Closure;
use ApexToolbox\Vendor\Laravel\SerializableClosure\Exceptions\InvalidSignatureException;
use ApexToolbox\Vendor\Laravel\SerializableClosure\Serializers\Signed;
use ApexToolbox\Vendor\Laravel\SerializableClosure\Signers\Hmac;
class SerializableClosure
{
    /**
     * The closure's serializable.
     *
     * @var \ApexToolbox\Vendor\Laravel\SerializableClosure\Contracts\Serializable
     */
    protected $serializable;
    /**
     * Creates a new serializable closure instance.
     *
     * @param  \Closure  $closure
     * @return void
     */
    public function __construct(Closure $closure)
    {
        $this->serializable = \ApexToolbox\Vendor\Laravel\SerializableClosure\Serializers\Signed::$signer ? new Serializers\Signed($closure) : new Serializers\Native($closure);
    }
    /**
     * Resolve the closure with the given arguments.
     *
     * @return mixed
     */
    public function __invoke()
    {
        return call_user_func_array($this->serializable, func_get_args());
    }
    /**
     * Gets the closure.
     *
     * @return \Closure
     */
    public function getClosure()
    {
        return $this->serializable->getClosure();
    }
    /**
     * Create a new unsigned serializable closure instance.
     *
     * @param  Closure  $closure
     * @return \ApexToolbox\Vendor\Laravel\SerializableClosure\UnsignedSerializableClosure
     */
    public static function unsigned(Closure $closure)
    {
        return new UnsignedSerializableClosure($closure);
    }
    /**
     * Sets the serializable closure secret key.
     *
     * @param  string|null  $secret
     * @return void
     */
    public static function setSecretKey($secret)
    {
        \ApexToolbox\Vendor\Laravel\SerializableClosure\Serializers\Signed::$signer = $secret ? new Hmac($secret) : null;
    }
    /**
     * Sets the transformer that should be used when serializing use variables.
     *
     * @param  \Closure|null  $transformer
     * @return void
     */
    public static function transformUseVariablesUsing($transformer)
    {
        \ApexToolbox\Vendor\Laravel\SerializableClosure\Serializers\Native::$transformUseVariables = $transformer;
    }
    /**
     * Sets the resolver that should be used when unserializing use variables.
     *
     * @param  \Closure|null  $resolver
     * @return void
     */
    public static function resolveUseVariablesUsing($resolver)
    {
        \ApexToolbox\Vendor\Laravel\SerializableClosure\Serializers\Native::$resolveUseVariables = $resolver;
    }
    /**
     * Get the serializable representation of the closure.
     *
     * @return array{serializable: \ApexToolbox\Vendor\Laravel\SerializableClosure\Serializers\Signed|\ApexToolbox\Vendor\Laravel\SerializableClosure\Contracts\Serializable}
     */
    public function __serialize()
    {
        return ['serializable' => $this->serializable];
    }
    /**
     * Restore the closure after serialization.
     *
     * @param  array{serializable: \ApexToolbox\Vendor\Laravel\SerializableClosure\Serializers\Signed|\ApexToolbox\Vendor\Laravel\SerializableClosure\Contracts\Serializable}  $data
     * @return void
     *
     * @throws \ApexToolbox\Vendor\Laravel\SerializableClosure\Exceptions\InvalidSignatureException
     */
    public function __unserialize($data)
    {
        if (Signed::$signer && !$data['serializable'] instanceof Signed) {
            throw new InvalidSignatureException();
        }
        $this->serializable = $data['serializable'];
    }
}