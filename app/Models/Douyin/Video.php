<?php

namespace App\Models\Douyin;

use App\Models\Product;
use App\Scopes\RuleScope;
use App\Util\Douyin\GetVideoListRequest;
use App\Util\Douyin\Request;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Arr;

class Video extends Model
{
    protected $table = 'douyin_videos';

    protected $fillable = [
        'aweme_id'
    ];

    public $statusValues = [
        102 => '公开',
        140 => '自己可见',
        143 => '好友可见',
        144 => '不适宜公开',
    ];

    protected static function boot()
    {
        parent::boot();

        static::addGlobalScope(new RuleScope());
    }

    public function dyuser()
    {
        return $this->belongsTo(User::class, 'author_user_id', 'uid');
    }

    public function products()
    {
        return $this->belongsToMany(Product::class,
            'video_has_product',
            'vid',
            'pid',
            'aweme_id',
            'product_id');
    }

    public function getDescAttribute()
    {
        return $this->attributes['desc'] ?: '无视频描述';
    }

    public function getStatisticsAttribute()
    {
        return [
            [
                'value' => $this->comment_count,
                'icon' => 'comment-processing',
                'title' => '评论',
            ], [
                'value' => $this->digg_count,
                'icon' => 'cards-heart',
                'title' => '点赞',
            ], [
                'value' => $this->play_count,
                'icon' => 'play-circle',
                'title' => '播放',
            ]
        ];
    }

    public function getDescLinkAttribute()
    {
        $link = '<a href="%s" target="_blank">%s</a>';

        return sprintf($link, $this->share_url, $this->desc);
    }

    public function getStatusValueDescAttribute()
    {
        return Arr::get($this->statusValues, $this->status_value);
    }

    //视频列表操作按钮
    public function getVideoBtnsAttribute()
    {
        $btns = [
            [
                'title' => $this->is_private ? '公开视频' : '隐藏',
                'icon' => $this->is_private ? 'eye' : 'eye-off',
                'url' => route('douyin.video.visibility', $this),
                'class' => '',
            ],
            [
                'title' => '查看评论',
                'icon' => 'comment-processing',
                'url' => '',
                'class' => 'bg-info',
            ],
        ];

        if (!$this->is_private) {
            $btns[] = [
                'title' => 'DOU+',
                'icon' => 'heart-flash',
                'url' => route('douplus.create', ['aweme_id' => $this->aweme_id]),
                'class' => 'bg-danger',
            ];
        }

        return $btns;
    }

    //视频发布时间
    public function getCreateTimeStrAttribute()
    {
        return date('Y年m月d日 H:i', $this->create_time);
    }

    //视频信息同步时间
    public function getSyncTimeDescAttribute()
    {
        return $this->info_update_time ? Carbon::parse($this->info_update_time)->diffForHumans() . '更新' : null;
    }

    //根据单条response 创建或更新视频
    public static function createByApi(array $aweme, User $user = null)
    {
        //过滤数据,只拿需要的字段
        $attr = self::getVideoAttr($aweme);
        $attr['info_update_time'] = date('Y-m-d H:i:s');

        //查找视频是否存在
        $video = self::query()->withoutGlobalScopes()->firstOrNew(Arr::only($attr, 'aweme_id'));

        //新视频填写Attr
        if (!$video->exists) {
            $attr = array_merge($attr, [
                'user_id' => $user->user_id,
                'group_id' => $user->group_id,
                'department_id' => $user->department_id,
            ]);
        }

        $video->forceFill($attr)->save();

        return $video;

    }

    //过滤api返回的信息,只拿需要的数据
    public static function getVideoAttr(array $aweme)
    {
        $attr = [];
        //字段映射
        $field = [
            'aweme_id',
            'author_user_id',
            'aweme_type',
            'create_time',
            'is_live_replay',
            'share_url',
            'desc',
            'rate',
            'status_value',
            'self_see' => 'status.self_see',
            'with_goods' => 'status.with_goods',
            'with_fusion_goods' => 'status.with_fusion_goods',
            'is_delete' => 'status.is_delete',
            'is_private' => 'status.is_private',
            'is_prohibited' => 'status.is_prohibited',
            'comment_count' => 'statistics.comment_count',
            'digg_count' => 'statistics.digg_count',
            'forward_count' => 'statistics.forward_count',
            'play_count' => 'statistics.play_count',
            'share_count' => 'statistics.share_count',
            'play_addr' => 'video.play_addr.url_list.0',
            'origin_cover' => 'video.origin_cover.url_list.0',
        ];
        //过滤
        foreach ($field as $k => $v) {
            if (is_numeric($k)) $k = $v;

            $attr[$k] = Arr::get($aweme, $v);
        }

        return $attr;
    }

    //是否带货
    public function isWithGoods(): bool
    {
        return $this->with_fusion_goods;
    }

    //智能优化key
    public function getDouplusGoodsAimKey()
    {
        $key = null;

        switch ($this->product_type) {
            case 1://小店
                $key = 53;
                break;
            case 2://淘宝
                $key = 113;
                break;
        }

        return $key;
    }

    //同步视频数据
    public function updateSelfByApi()
    {
        $apiRequest = new Request();

        $re = new GetVideoListRequest();

        $re->setVideo($this);

        //获取视频信息并更新
        return Video::createByApi($apiRequest->request($re, $this->dyuser));
    }
}
