<?php namespace Milky\Account\Models;

use Milky\Database\Eloquent\Model;

class GroupInheritance extends Model
{
    protected $table = "group_inheritance";
    protected $fillable = ["weight", "child", "parent", "type"];
    public $timestamps = false;

    public function group()
    {
        return $this->hasOne(Group::class, "id", "parent");
    }

    public function assignedTo()
    {
        if ( $this->type == 0 )
            return $this->belongsTo( Group::class, "child" );
        if ( $this->type == 1 )
            return $this->belongsTo( User::class, "child" );
	    throw new \InvalidArgumentException( "The [GroupInheritance] type must be either 0 (group) or 1 (user). Got [" . $this->type . "]." );
    }
}
